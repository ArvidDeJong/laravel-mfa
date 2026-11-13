<?php

declare(strict_types=1);

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\MfaServiceProvider;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

it('calls a guard that leans on one service at risk', function () {
    config(['mfa.guards' => ['staff' => ['channels' => ['mail'], 'home' => '/cms', 'identifier' => 'email', 'remember' => false]]]);

    expect(Mfa::resilient('staff'))->toBeFalse();

    $this->artisan('mfa:check')->expectsOutputToContain('AT RISK')->assertSuccessful();
    $this->artisan('mfa:check', ['--strict' => true])->assertFailed();
});

it('calls a guard with two different services or an independent channel resilient', function (array $channels) {
    config(['mfa.guards.staff.channels' => $channels]);

    expect(Mfa::resilient('staff'))->toBeTrue();
})->with([
    'mail and sms' => [['mail', 'sms']],
    'mail and authenticator' => [['mail', 'authenticator']],
]);

it('counts the users who have set up a fallback', function () {
    config(['mfa.guards' => ['staff' => ['channels' => ['mail', 'recovery'], 'home' => '/cms', 'identifier' => 'email', 'remember' => false]]]);

    $this->staff('one@example.com');
    app(Enrollment::class)->generateRecoveryCodes($this->staff('two@example.com'));

    $this->artisan('mfa:check', ['--strict' => true])
        ->expectsOutputToContain('1 of 2 users have a fallback')
        ->assertSuccessful();
});

it('prints a one time link that signs a user in', function () {
    $this->staff();

    Artisan::call('mfa:link', ['guard' => 'staff', 'identifier' => 'jan@example.com']);

    preg_match('#https?://\S+/mfa/link/\w{64}#', Artisan::output(), $match);

    expect($match)->not->toBe([])
        ->and(LoginAttempt::query()->where('result', LoginAttempt::EMERGENCY_LINK_ISSUED)->count())->toBe(1);

    $this->post($match[0])->assertRedirect('/cms');

    expect(Auth::guard('staff')->check())->toBeTrue();
});

it('prints no link for an unknown user or guard', function () {
    $this->artisan('mfa:link', ['guard' => 'staff', 'identifier' => 'nobody@example.com'])->assertFailed();
    $this->artisan('mfa:link', ['guard' => 'api', 'identifier' => 'jan@example.com'])->assertFailed();

    expect(Challenge::query()->count())->toBe(0);
});

it('prunes expired challenges and old log lines', function () {
    $staff = $this->staff();
    Challenge::issue('staff', $staff, 'mail', true);
    Mfa::record('staff', LoginAttempt::INVALID);

    $this->travel(91)->days();

    $this->artisan('mfa:prune')->assertSuccessful();

    expect(Challenge::query()->count())->toBe(0)
        ->and(LoginAttempt::query()->count())->toBe(0);
});

it('offers config, migrations, views and translations for publishing', function () {
    $paths = ServiceProvider::pathsToPublish(MfaServiceProvider::class);

    expect(array_values($paths))->toContain(config_path('mfa.php'), database_path('migrations'), resource_path('views/vendor/mfa'));
});

it('ships every translation key in every locale', function () {
    $en = require packageRoot('resources/lang/en/mfa.php');
    $nl = require packageRoot('resources/lang/nl/mfa.php');

    expect(array_keys($nl))->toBe(array_keys($en));
});
