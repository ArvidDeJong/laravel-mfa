<?php

declare(strict_types=1);

use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Firewall\Verdict;
use Darvis\Mfa\Livewire\FirewallRules;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Darvis\Mfa\Tests\Fixtures\Staff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The code of the login mail that went out last.
 */
function lastMailedCode(): string
{
    $code = '';

    Notification::assertSentOnDemand(LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return $code;
}

beforeEach(function () {
    Notification::fake();
    config(['mfa.guards.staff.channels' => ['password', 'mail']]);
});

it('checks a password as a first step without signing in, then signs in with the mailed code', function () {
    $staff = $this->staff();

    expect(Mfa::checkSecret('staff', 'jan@example.com', 'password', 'wrong'))->toBeNull()
        ->and(Mfa::checkSecret('staff', 'jan@example.com', 'password', 'correct horse')?->is($staff))->toBeTrue()
        ->and(Auth::guard('staff')->check())->toBeFalse();

    Mfa::request('staff', 'jan@example.com', 'mail');

    expect(Mfa::loginWithCode('staff', 'jan@example.com', lastMailedCode()))->not->toBeNull()
        ->and(LoginAttempt::query()->orderBy('id')->pluck('result')->all())
        ->toBe([LoginAttempt::INVALID, LoginAttempt::VERIFIED, LoginAttempt::SUCCEEDED]);
});

it('counts a wrong first step towards a block of the address', function () {
    $this->staff();
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 2, 'mfa.rate_limit.attempts' => 50]);

    Mfa::checkSecret('staff', 'jan@example.com', 'password', 'wrong');
    Mfa::checkSecret('staff', 'jan@example.com', 'password', 'wrong');

    expect(fn () => Mfa::checkSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toThrow(BlockedException::class);
});

it('does not pass an inactive account in the first step', function () {
    $this->staff(active: false);

    expect(Mfa::checkSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toBeNull()
        ->and(LoginAttempt::query()->sole()->result)->toBe(LoginAttempt::INACTIVE);
});

it('sends nothing to an account that switched the channel off, and looks the same from the outside', function () {
    $staff = $this->staff();
    Staff::$restricted = ['mail'];

    expect(Mfa::available('staff', $staff))->toBe(['password']);

    Mfa::request('staff', 'jan@example.com');

    Notification::assertNothingSent();

    expect(Challenge::query()->count())->toBe(0)
        ->and(LoginAttempt::query()->sole()->result)->toBe(LoginAttempt::NOT_ALLOWED)
        ->and(Mfa::loginWithCode('staff', 'jan@example.com', '123456'))->toBeNull();
});

it('refuses a code and a link that were mailed before the account switched the channel off', function () {
    $this->staff();
    $token = null;

    Mfa::sendMailUsing(function ($user, $issued) use (&$token, &$code) {
        $token = $issued->token;
        $code = $issued->code;
    });

    Mfa::request('staff', 'jan@example.com', 'mail');
    Mfa::sendMailUsing(null);

    Staff::$restricted = ['mail'];

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $code))->toBeNull()
        ->and(Mfa::loginWithLink($token))->toBeNull()
        ->and(Auth::guard('staff')->check())->toBeFalse()
        ->and(LoginAttempt::query()->where('result', LoginAttempt::NOT_ALLOWED)->count())->toBe(2);
});

it('refuses a secret on a channel the account switched off', function () {
    $this->staff();
    Staff::$restricted = ['password'];

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toBeNull()
        ->and(LoginAttempt::query()->sole()->result)->toBe(LoginAttempt::NOT_ALLOWED);
});

it('says until when a block lasts, and remembers who lifted it', function () {
    Gate::define('manageMfa', fn ($user = null) => true);
    $staff = $this->staff();
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 1, 'mfa.firewall.block_minutes' => 15]);

    Mfa::loginWithCode('staff', 'nobody@example.com', '000000');

    $verdict = app(Firewall::class)->decide('127.0.0.1', 'staff');

    expect($verdict->reason)->toBe(Verdict::TOO_MANY_FAILURES)
        ->and(abs(now()->addMinutes(15)->getTimestamp() - $verdict->until->getTimestamp()))->toBeLessThan(5);

    try {
        Mfa::request('staff', 'jan@example.com');
    } catch (BlockedException $exception) {
        expect($exception->until?->equalTo($verdict->until))->toBeTrue();
    }

    $block = FirewallRule::query()->sole();

    // Lifted on the firewall screen by whoever is signed in. The gate lets the test in.
    config(['mfa.firewall.enabled' => false]);
    Auth::guard('staff')->login($staff);
    Livewire::test(FirewallRules::class)->call('remove', $block->id);
    config(['mfa.firewall.enabled' => true]);

    $block->refresh();

    expect($block->lifted_by)->toBe('jan@example.com')
        ->and($block->lifted_at)->not->toBeNull()
        ->and(app(Firewall::class)->allows('127.0.0.1', 'staff'))->toBeTrue();
});

it('removes a rule that somebody added by hand, and records who added it', function () {
    Gate::define('manageMfa', fn ($user = null) => true);
    Auth::guard('staff')->login($this->staff());

    $screen = Livewire::test(FirewallRules::class)->set('range', '203.0.113.9')->call('add');
    $rule = FirewallRule::query()->sole();

    expect($rule->created_by)->toBe('jan@example.com');

    $screen->call('remove', $rule->id);

    expect(FirewallRule::query()->count())->toBe(0);
});
