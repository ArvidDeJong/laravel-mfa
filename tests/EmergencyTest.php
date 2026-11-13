<?php

declare(strict_types=1);

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Exceptions\EmergencyException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\Emergency;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

it('fails closed on a date it cannot use', function (?string $value) {
    config(['mfa.emergency.unavailable_until.mail' => $value]);

    expect(app(Emergency::class)->unavailableUntil('mail'))->toBeNull();
})->with([
    'empty' => [null],
    'unreadable' => ['next blue moon'],
    'in the past' => ['2020-01-01 12:00'],
    'beyond the maximum' => ['2099-01-01 12:00'],
]);

it('skips the channels of a service that is declared down', function () {
    Notification::fake();
    $staff = $this->staff();

    expect(Mfa::available('staff', $staff))->toBe(['mail']);

    config(['mfa.emergency.unavailable_until.mail' => now()->addHours(2)->toDateTimeString()]);

    expect(Mfa::available('staff', $staff))->toBe([]);

    Mfa::request('staff', 'jan@example.com');
    Notification::assertNothingSent();

    $this->travel(3)->hours();

    expect(Mfa::available('staff', $staff))->toBe(['mail']);
});

it('refuses a password while password only login is off', function () {
    $this->staff();
    config(['mfa.emergency.unavailable_until.mail' => now()->addHour()->toDateTimeString()]);

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toBeNull();
});

it('lets a user without any working channel in with a password during an emergency', function () {
    $this->staff();
    config([
        'mfa.emergency.allow_password_only' => true,
        'mfa.emergency.unavailable_until.mail' => now()->addHour()->toDateTimeString(),
    ]);

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong'))->toBeNull()
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->not->toBeNull()
        ->and(LoginAttempt::query()->where('result', LoginAttempt::EMERGENCY)->count())->toBe(1);
});

it('keeps asking for the other channel from a user who has one', function () {
    $staff = $this->staff();
    app(Enrollment::class)->generateRecoveryCodes($staff);

    config([
        'mfa.emergency.allow_password_only' => true,
        'mfa.emergency.unavailable_until.mail' => now()->addHour()->toDateTimeString(),
    ]);

    expect(Mfa::passwordOnlyAllowed('staff', $staff))->toBeFalse()
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toBeNull();
});

it('never allows password only login without an emergency', function () {
    $staff = $this->staff();
    config(['mfa.emergency.allow_password_only' => true, 'mfa.guards.staff.channels' => ['sms']]);

    expect(Mfa::available('staff', $staff))->toBe([])
        ->and(Mfa::passwordOnlyAllowed('staff', $staff))->toBeFalse();
});

it('ends an emergency session once the emergency is over', function () {
    Route::middleware(['web', 'mfa.emergency'])->get('/cms', fn () => 'cms');

    $this->staff();
    config([
        'mfa.emergency.allow_password_only' => true,
        'mfa.emergency.unavailable_until.mail' => now()->addHour()->toDateTimeString(),
    ]);

    $this->startSession();
    request()->setLaravelSession($this->app['session.store']);

    Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse');

    $this->get('/cms')->assertOk();

    $this->travel(2)->hours();

    $this->get('/cms')->assertRedirect('/cms');

    expect(Auth::guard('staff')->check())->toBeFalse();
});

it('declares and ends an emergency from the command line', function () {
    $this->artisan('mfa:emergency', ['dependency' => 'mail', '--until' => now()->addHours(2)->toDateTimeString()])->assertSuccessful();

    expect(app(Emergency::class)->isUnavailable('mail'))->toBeTrue();

    $this->artisan('mfa:emergency', ['dependency' => 'mail', '--off' => true])->assertSuccessful();

    expect(app(Emergency::class)->isUnavailable('mail'))->toBeFalse();
});

it('refuses an emergency that is too long, in the past or for an unknown service', function () {
    $this->artisan('mfa:emergency', ['dependency' => 'mail', '--until' => now()->addDays(3)->toDateTimeString()])->assertFailed();
    $this->artisan('mfa:emergency', ['dependency' => 'mail', '--until' => '2020-01-01'])->assertFailed();
    $this->artisan('mfa:emergency', ['dependency' => 'pigeon', '--until' => now()->addHour()->toDateTimeString()])->assertFailed();
    $this->artisan('mfa:emergency', ['dependency' => 'mail'])->assertFailed();

    expect(fn () => app(Emergency::class)->declare('mail', now()->addDays(3)))->toThrow(EmergencyException::class)
        ->and(app(Emergency::class)->isUnavailable('mail'))->toBeFalse();
});
