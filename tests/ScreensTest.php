<?php

declare(strict_types=1);

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Livewire\FirewallRules;
use Darvis\Mfa\Livewire\Login;
use Darvis\Mfa\Livewire\LoginAttempts;
use Darvis\Mfa\Livewire\Settings;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Darvis\Mfa\Support\QrCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/admin/login', Login::class)->defaults('guard', 'staff');
        Route::get('/login', Login::class)->defaults('guard', 'web');
        Route::get('/admin/security', Settings::class)->defaults('guard', 'staff');
        Route::get('/admin/firewall', FirewallRules::class);
        Route::get('/admin/attempts', LoginAttempts::class);
    });
});

it('renders the login screen in the layout of its guard, else in the general one', function () {
    config(['mfa.guards.staff.layout' => 'layouts.staff']);

    $this->get('/admin/login')->assertOk()->assertSee('data-layout="staff"', false)->assertSee('Sign in');
    $this->get('/login')->assertOk()->assertSee('data-layout="app"', false);
});

it('signs in on the login screen with the mailed code', function () {
    Notification::fake();
    $this->staff();

    $screen = Livewire::test(Login::class, ['guard' => 'staff'])
        ->assertSet('channel', 'mail')
        ->set('identifier', 'jan@example.com')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $code = null;
    Notification::assertSentOnDemand(LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    $screen->set('secret', $code === '000000' ? '111111' : '000000')->call('signIn')->assertHasErrors('secret');
    $screen->set('secret', $code)->call('signIn')->assertHasNoErrors()->assertRedirect('/cms');

    expect(Auth::guard('staff')->check())->toBeTrue();
});

it('turns the screen the same way for an unknown address', function () {
    Notification::fake();

    Livewire::test(Login::class, ['guard' => 'staff'])
        ->set('identifier', 'nobody@example.com')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Notification::assertNothingSent();
});

it('signs in on the login screen with an authenticator code', function () {
    $staff = $this->staff();
    $enrollment = app(Enrollment::class);
    $factor = $enrollment->startAuthenticator($staff);
    $otp = fn () => app(Google2FA::class)->oathTotp($factor->secret, intdiv(now()->getTimestamp(), 30));
    $enrollment->confirmAuthenticator($staff, $otp());

    $this->travel(61)->seconds();

    Livewire::test(Login::class, ['guard' => 'staff'])
        ->call('choose', 'authenticator')
        ->assertSet('channel', 'authenticator')
        ->set('identifier', 'jan@example.com')
        ->set('secret', $otp())
        ->call('signIn')
        ->assertRedirect('/cms');
});

it('offers the pin on the login screen only on a location', function () {
    config(['mfa.guards.staff.channels' => ['mail', 'pin']]);

    Livewire::test(Login::class, ['guard' => 'staff'])->assertDontSee('PIN')->call('choose', 'pin')->assertSet('channel', 'mail');

    config(['mfa.firewall.locations' => ['127.0.0.1']]);

    Livewire::test(Login::class, ['guard' => 'staff'])->assertSee('PIN')->call('choose', 'pin')->assertSet('channel', 'pin');
});

it('shows why the firewall refuses on the login screen', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.deny' => ['127.0.0.1']]);

    Livewire::test(Login::class, ['guard' => 'staff'])
        ->set('identifier', 'jan@example.com')
        ->call('send')
        ->assertHasErrors('identifier')
        ->assertSet('sent', false);
});

it('does not let the visitor change the guard of a screen', function () {
    Livewire::test(Login::class, ['guard' => 'staff'])->set('guard', 'web');
})->throws(Exception::class);

it('needs a signed-in user for the settings screen', function () {
    $this->get('/admin/security')->assertForbidden();

    $this->actingAs($this->staff(), 'staff')->get('/admin/security')->assertOk()->assertSee('Authenticator app');
});

it('sets up an authenticator, recovery codes and a pin on the settings screen', function () {
    config(['mfa.guards.staff.channels' => ['mail', 'authenticator', 'recovery', 'pin']]);
    $staff = $this->staff();
    Auth::guard('staff')->login($staff);

    $screen = Livewire::test(Settings::class, ['guard' => 'staff'])->call('startAuthenticator');
    $secret = $screen->get('authenticatorSecret');

    expect($secret)->not->toBeNull();

    $screen->set('authenticatorCode', '000000')->call('confirmAuthenticator')->assertHasErrors('authenticatorCode');
    $screen->set('authenticatorCode', app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30)))
        ->call('confirmAuthenticator')
        ->assertHasNoErrors()
        ->assertSet('authenticatorSecret', null);

    $screen->call('generateRecoveryCodes');

    expect($screen->get('recoveryCodes'))->toHaveCount(8);

    $screen->set('pin', '1234')->call('savePin')->assertHasErrors('pin');
    $screen->set('pin', '4071')->call('savePin')->assertHasNoErrors();

    expect(Factor::confirmed($staff, Factor::AUTHENTICATOR)->exists())->toBeTrue()
        ->and(Factor::confirmed($staff, Factor::PIN)->exists())->toBeTrue();

    $screen->call('remove', 'pin');

    expect(Factor::confirmed($staff, Factor::PIN)->exists())->toBeFalse();
});

it('keeps the firewall and login log screens closed without the gate', function () {
    $this->get('/admin/firewall')->assertForbidden();
    $this->get('/admin/attempts')->assertForbidden();

    Gate::define('manageMfa', fn ($user = null) => true);

    $this->get('/admin/firewall')->assertOk()->assertSee('Firewall');
    $this->get('/admin/attempts')->assertOk()->assertSee('Login attempts');
});

it('adds and removes firewall rules on screen', function () {
    Gate::define('manageMfa', fn ($user = null) => true);

    $screen = Livewire::test(FirewallRules::class)
        ->set('range', 'nonsense')->call('add')->assertHasErrors('range')
        ->set('range', '203.0.113.0/24')->set('until', '2020-01-01')->call('add')->assertHasErrors('until')
        ->set('until', now()->addWeek()->toDateString())
        ->set('note', 'Timo, hotel Istanbul')
        ->set('allowsPin', true)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSee('Timo, hotel Istanbul');

    $rule = FirewallRule::query()->sole();

    expect($rule->is_location)->toBeTrue()->and($rule->allows_pin)->toBeTrue();

    $screen->call('remove', $rule->id);

    expect(FirewallRule::query()->count())->toBe(0);
});

it('lets the "liftMfaBlocks" gate lift a block without managing the rules', function () {
    Gate::define('manageMfa', fn ($user = null) => false);
    Gate::define('liftMfaBlocks', fn ($user = null) => true);

    $exception = FirewallRule::query()->create(['action' => FirewallRule::ALLOW, 'range' => '203.0.113.5', 'note' => 'Timo, hotel Istanbul']);
    $block = FirewallRule::query()->create(['action' => FirewallRule::DENY, 'automatic' => true, 'range' => '203.0.113.9', 'expires_at' => now()->addMinutes(15)]);

    $screen = Livewire::test(FirewallRules::class)
        ->assertSee('203.0.113.9')
        ->assertDontSee('Timo, hotel Istanbul');

    $screen->set('range', '203.0.113.7')->call('add')->assertForbidden();
    Livewire::test(FirewallRules::class)->call('remove', $exception->id)->assertForbidden();
    Livewire::test(FirewallRules::class)->call('remove', $block->id)->assertOk();

    expect(FirewallRule::query()->count())->toBe(2)
        ->and($block->refresh()->lifted_at)->not->toBeNull();

    $this->get('/admin/attempts')->assertForbidden();
});

it('lists and filters the login log', function () {
    Gate::define('manageMfa', fn ($user = null) => true);

    LoginAttempt::query()->create(['guard' => 'staff', 'identifier' => 'jan@example.com', 'result' => LoginAttempt::SUCCEEDED, 'channel' => 'mail', 'method' => 'code']);
    LoginAttempt::query()->create(['guard' => 'staff', 'identifier' => 'eve@example.com', 'result' => LoginAttempt::INVALID, 'channel' => 'mail', 'method' => 'code']);

    Livewire::test(LoginAttempts::class)
        ->assertSee('jan@example.com')
        ->assertSee('eve@example.com')
        ->set('failedOnly', true)
        ->assertDontSee('jan@example.com')
        ->assertSee('eve@example.com')
        ->set('failedOnly', false)
        ->set('search', 'jan@')
        ->assertSee('jan@example.com')
        ->assertDontSee('eve@example.com');
});

it('draws the authenticator key as a QR code', function () {
    expect(QrCode::svg('otpauth://totp/Example:jan@example.com?secret=ABC'))->toStartWith('<svg');
});
