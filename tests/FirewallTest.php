<?php

declare(strict_types=1);

use Darvis\Mfa\Contracts\CountryResolver;
use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Firewall\CountryManager;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Firewall\Verdict;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Tests run from 127.0.0.1.
 */
function firewall(array $config = []): Firewall
{
    config(['mfa.firewall' => array_merge(config('mfa.firewall'), ['enabled' => true], $config)]);

    return app(Firewall::class);
}

/**
 * A country source that answers from a fixed table.
 *
 * @param  array<string, string>  $countries  Address to ISO code.
 */
function countries(array $countries): void
{
    app(CountryManager::class)->extend('fake', fn () => new class($countries) implements CountryResolver
    {
        public function __construct(private array $countries) {}

        public function country(?string $ip): ?string
        {
            return $this->countries[$ip] ?? null;
        }
    });

    config(['mfa.firewall.country_driver' => 'fake']);
}

function rule(array $attributes): FirewallRule
{
    $rule = FirewallRule::query()->create(array_merge(['action' => FirewallRule::ALLOW, 'range' => '127.0.0.1'], $attributes));

    app(Firewall::class)->refresh();

    return $rule;
}

it('lets everything in while it is switched off', function () {
    config(['mfa.firewall.deny' => ['127.0.0.1']]);

    expect(app(Firewall::class)->allows('127.0.0.1'))->toBeTrue();
});

it('refuses an address on the deny list, from the config or the database', function () {
    expect(firewall(['deny' => ['203.0.113.0/24']])->decide('203.0.113.9')->reason)->toBe(Verdict::DENIED)
        ->and(app(Firewall::class)->allows('198.51.100.1'))->toBeTrue();

    rule(['action' => FirewallRule::DENY, 'range' => '198.51.100.1']);

    expect(app(Firewall::class)->allows('198.51.100.1'))->toBeFalse();
});

it('puts the deny list before the allow list', function () {
    expect(firewall(['allow' => ['203.0.113.9'], 'deny' => ['203.0.113.0/24']])->allows('203.0.113.9'))->toBeFalse();
});

it('only lets in the allowed countries, and fails closed on an unknown one', function () {
    countries(['198.51.100.1' => 'NL', '203.0.113.9' => 'US']);
    $firewall = firewall(['countries' => ['nl']]);

    expect($firewall->allows('198.51.100.1'))->toBeTrue()
        ->and($firewall->decide('203.0.113.9')->reason)->toBe(Verdict::COUNTRY)
        ->and($firewall->decide('192.0.2.1')->reason)->toBe(Verdict::COUNTRY);

    config(['mfa.firewall.allow_unknown_country' => true]);

    expect($firewall->allows('192.0.2.1'))->toBeTrue();
});

it('lets an allowed address in from any country, until its rule runs out', function () {
    countries(['203.0.113.9' => 'TR']);
    $firewall = firewall(['countries' => ['NL']]);

    rule(['range' => '203.0.113.9', 'note' => 'Timo, hotel Istanbul', 'expires_at' => now()->addDays(3)]);

    expect($firewall->allows('203.0.113.9'))->toBeTrue();

    $this->travel(4)->days();
    $firewall->refresh();

    expect($firewall->allows('203.0.113.9'))->toBeFalse();
});

it('filters one guard on country and leaves the other alone', function () {
    countries(['203.0.113.9' => 'ES']);
    $firewall = firewall(['countries' => []]);
    config(['mfa.guards.staff.countries' => ['NL']]);

    // The CMS only from the Netherlands, a visitor of the site from anywhere.
    expect($firewall->decide('203.0.113.9', 'staff')->reason)->toBe(Verdict::COUNTRY)
        ->and($firewall->allows('203.0.113.9', 'web'))->toBeTrue()
        ->and($firewall->allows('203.0.113.9'))->toBeTrue()
        ->and($firewall->country('203.0.113.9'))->toBe('ES');
});

it('lets a guard switch a general country filter off for itself', function () {
    countries(['203.0.113.9' => 'ES']);
    $firewall = firewall(['countries' => ['NL']]);
    config(['mfa.guards.web.countries' => []]);

    expect($firewall->allows('203.0.113.9', 'web'))->toBeTrue()
        ->and($firewall->allows('203.0.113.9', 'staff'))->toBeFalse();
});

it('keeps a rule to its guard', function () {
    countries([]);
    $firewall = firewall(['countries' => ['NL']]);

    rule(['range' => '203.0.113.9', 'guard' => 'staff']);

    expect($firewall->allows('203.0.113.9', 'staff'))->toBeTrue()
        ->and($firewall->allows('203.0.113.9', 'web'))->toBeFalse();
});

it('reads the country from a proxy header, for the visitor only', function () {
    config(['mfa.firewall.country_driver' => 'header']);
    $firewall = firewall(['countries' => ['NL']]);

    request()->headers->set('CF-IPCountry', 'nl');

    expect($firewall->allows('127.0.0.1'))->toBeTrue()
        ->and($firewall->allows('203.0.113.9'))->toBeFalse();

    request()->headers->set('CF-IPCountry', 'XX');

    expect($firewall->allows('127.0.0.1'))->toBeFalse();
});

it('refuses a login from a blocked address and logs why', function () {
    Notification::fake();
    $this->staff();
    firewall(['deny' => ['127.0.0.1']]);

    expect(fn () => Mfa::request('staff', 'jan@example.com'))->toThrow(BlockedException::class)
        ->and(fn () => Mfa::loginWithCode('staff', 'jan@example.com', '123456'))->toThrow(BlockedException::class);

    Notification::assertNothingSent();

    expect(LoginAttempt::query()->where('result', Verdict::DENIED)->count())->toBe(2);
});

it('blocks an address after too many wrong guesses and lets it back in when the block is lifted', function () {
    Notification::fake();
    $this->staff();
    firewall(['block_after_attempts' => 3]);
    config(['mfa.rate_limit.attempts' => 50]);

    Mfa::request('staff', 'jan@example.com');

    foreach (range(1, 3) as $ignored) {
        Mfa::loginWithCode('staff', 'jan@example.com', 'wrong');
    }

    $block = FirewallRule::query()->where('automatic', true)->sole();

    expect($block->action)->toBe(FirewallRule::DENY)
        ->and($block->range)->toBe('127.0.0.1')
        ->and(fn () => Mfa::request('staff', 'jan@example.com'))->toThrow(BlockedException::class);

    $block->delete();
    app(Firewall::class)->refresh();

    Mfa::request('staff', 'jan@example.com');

    expect(LoginAttempt::query()->where('result', Verdict::TOO_MANY_FAILURES)->count())->toBe(1);
});

it('never blocks a location for wrong guesses', function () {
    Notification::fake();
    $this->staff();
    firewall(['block_after_attempts' => 2, 'locations' => ['127.0.0.1']]);
    config(['mfa.rate_limit.attempts' => 50]);

    Mfa::request('staff', 'jan@example.com');

    foreach (range(1, 4) as $ignored) {
        Mfa::loginWithCode('staff', 'jan@example.com', 'wrong');
    }

    expect(FirewallRule::query()->count())->toBe(0);
});

it('does not count asking for a mail towards a block', function () {
    firewall(['block_after_attempts' => 2]);
    config(['mfa.rate_limit.attempts' => 50]);

    foreach (range(1, 4) as $ignored) {
        Mfa::request('staff', 'nobody@example.com');
    }

    expect(FirewallRule::query()->count())->toBe(0);
});

it('opens routes behind the location middleware only on site', function () {
    Route::get('/kantoor', fn () => 'kantoor')->middleware('mfa.location');
    Route::get('/kantoor-staff', fn () => 'kantoor')->middleware('mfa.location:staff');

    $this->get('/kantoor')->assertForbidden();

    rule(['is_location' => true, 'guard' => 'web']);

    // A location of another guard does not open the staff routes.
    $this->get('/kantoor-staff')->assertForbidden();
    $this->get('/kantoor')->assertOk();

    config(['mfa.firewall.locations' => ['127.0.0.0/8']]);

    $this->get('/kantoor-staff')->assertOk();
});

it('does not treat an allowed address as a location', function () {
    Route::get('/kantoor', fn () => 'kantoor')->middleware('mfa.location');

    rule(['is_location' => false]);

    $this->get('/kantoor')->assertForbidden();
});

it('refuses and signs out behind the firewall middleware', function () {
    Route::get('/cms', fn () => 'cms')->middleware(['web', 'mfa.firewall:staff']);

    $this->actingAs($this->staff(), 'staff')->get('/cms')->assertOk();

    firewall(['deny' => ['127.0.0.1']]);

    $this->get('/cms')->assertForbidden();

    expect(Auth::guard('staff')->check())->toBeFalse();
});

it('leaves a login on another guard alone when it signs one guard out', function () {
    Route::get('/cms', fn () => 'cms')->middleware(['web', 'mfa.firewall:staff']);

    $this->actingAs($this->user(), 'web')->actingAs($this->staff(), 'staff');

    firewall(['deny' => ['127.0.0.1']]);

    $this->get('/cms')->assertForbidden();

    expect(Auth::guard('staff')->check())->toBeFalse()
        ->and(Auth::guard('web')->check())->toBeTrue();
});

it('logs a refused link for its owner', function () {
    $staff = $this->staff();
    $token = null;

    Mfa::sendMailUsing(function ($user, $issued) use (&$token) {
        $token = $issued->token;
    });

    Mfa::request('staff', 'jan@example.com');
    Mfa::sendMailUsing(null);

    firewall(['deny' => ['127.0.0.1']]);

    expect(fn () => Mfa::loginWithLink($token))->toThrow(BlockedException::class);

    $attempt = LoginAttempt::query()->where('result', Verdict::DENIED)->sole();

    expect($attempt->authenticatable_id)->toBe((string) $staff->id)
        ->and($attempt->method)->toBe(LoginAttempt::METHOD_LINK);
});

it('offers the pin channel only on a location that allows it', function () {
    config(['mfa.guards.staff.channels' => ['mail', 'pin']]);
    $staff = $this->staff();
    app(Enrollment::class)->setPin($staff, '4071');

    expect(Mfa::available('staff', $staff))->toBe(['mail'])
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '4071'))->toBeNull();

    rule(['is_location' => true, 'allows_pin' => false]);

    expect(Mfa::available('staff', $staff))->toBe(['mail']);

    FirewallRule::query()->update(['allows_pin' => true]);
    app(Firewall::class)->refresh();

    expect(Mfa::available('staff', $staff))->toBe(['mail', 'pin'])
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '4071'))->not->toBeNull();
});

it('locks a pin after too many wrong tries until a new one is set', function () {
    config(['mfa.guards.staff.channels' => ['pin'], 'mfa.firewall.locations' => ['127.0.0.1'], 'mfa.pin.max_attempts' => 2, 'mfa.rate_limit.attempts' => 50]);
    $staff = $this->staff();
    $enrollment = app(Enrollment::class);
    $enrollment->setPin($staff, '4071');

    Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '0000');
    Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '1111');

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '4071'))->toBeNull()
        ->and(Mfa::available('staff', $staff))->toBe([]);

    $enrollment->setPin($staff, '8263');

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'pin', '8263'))->not->toBeNull();
});

it('refuses a pin that is too short or easy to guess, and stores only a hash', function (string $pin) {
    expect(fn () => app(Enrollment::class)->setPin($this->staff(), $pin))->toThrow(InvalidArgumentException::class);
})->with(['123', '0000', '1234', '9876', '55555', '12ab']);

it('stores a pin hashed', function () {
    $staff = $this->staff();
    app(Enrollment::class)->setPin($staff, '4071');

    expect(Factor::owned($staff, Factor::PIN)->sole()->secret)->not->toBe('4071');
});

it('lets an address in from the command line', function () {
    $this->artisan('mfa:allow-ip', ['ip' => '203.0.113.0/24', '--until' => now()->addDays(2)->toDateString(), '--note' => 'Timo, hotel Istanbul', '--pin' => true])->assertSuccessful();

    $rule = FirewallRule::query()->sole();

    expect($rule->action)->toBe(FirewallRule::ALLOW)
        ->and($rule->is_location)->toBeTrue()
        ->and($rule->allows_pin)->toBeTrue()
        ->and($rule->expires_at?->isFuture())->toBeTrue();

    $this->artisan('mfa:allow-ip', ['ip' => 'not an address'])->assertFailed();
    $this->artisan('mfa:allow-ip', ['ip' => '203.0.113.1', '--until' => '2020-01-01'])->assertFailed();
    $this->artisan('mfa:allow-ip', ['ip' => '203.0.113.1/40'])->assertFailed();
});

it('prunes firewall rules that have run out and keeps the rest', function () {
    rule(['range' => '203.0.113.1', 'expires_at' => now()->addDay()]);
    rule(['range' => '203.0.113.2']);

    $this->travel(2)->days();
    $this->artisan('mfa:prune')->assertSuccessful();

    expect(FirewallRule::query()->pluck('range')->all())->toBe(['203.0.113.2']);
});
