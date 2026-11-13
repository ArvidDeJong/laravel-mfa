<?php

declare(strict_types=1);

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\Factor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

function currentOtp(string $secret): string
{
    return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
}

function enrollAuthenticator($user): string
{
    $enrollment = app(Enrollment::class);
    $factor = $enrollment->startAuthenticator($user);

    expect($enrollment->confirmAuthenticator($user, currentOtp($factor->secret)))->toBeTrue();

    return $factor->secret;
}

it('only counts an authenticator once it is confirmed', function () {
    $staff = $this->staff();
    $enrollment = app(Enrollment::class);

    $factor = $enrollment->startAuthenticator($staff);

    expect(Mfa::available('staff', $staff))->not->toContain('authenticator')
        ->and($enrollment->authenticatorUri($staff, $factor))->toStartWith('otpauth://totp/')
        ->and($enrollment->confirmAuthenticator($staff, '000000'))->toBeFalse();

    enrollAuthenticator($staff);

    expect(Mfa::available('staff', $staff))->toContain('authenticator');
});

it('stores the authenticator secret encrypted', function () {
    $secret = enrollAuthenticator($this->staff());

    expect(DB::table('mfa_factors')->value('secret'))->not->toBe($secret);
});

it('signs in with an authenticator code, and not twice with the same one', function () {
    $staff = $this->staff();
    $secret = enrollAuthenticator($staff);

    // The confirmation used the current time step; move to the next one.
    $this->travel(61)->seconds();
    $code = currentOtp($secret);

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'authenticator', $code))->not->toBeNull()
        ->and(Auth::guard('staff')->check())->toBeTrue();

    Auth::guard('staff')->logout();

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'authenticator', $code))->toBeNull();
});

it('refuses a channel the guard does not offer', function () {
    config(['mfa.guards.staff.channels' => ['mail']]);
    $staff = $this->staff();
    $secret = enrollAuthenticator($staff);
    $this->travel(61)->seconds();

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'authenticator', currentOtp($secret)))->toBeNull()
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toBeNull();
});

it('hands out recovery codes that each work once', function () {
    $staff = $this->staff();
    $codes = app(Enrollment::class)->generateRecoveryCodes($staff);

    expect($codes)->toHaveCount(8)
        ->and(Mfa::loginWithSecret('staff', 'jan@example.com', 'recovery', strtoupper($codes[0])))->not->toBeNull();

    Auth::guard('staff')->logout();

    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'recovery', $codes[0]))->toBeNull()
        ->and(Factor::owned($staff, Factor::RECOVERY)->count())->toBe(7);
});

it('confirms a phone number before text messages count', function () {
    Log::spy();
    $staff = $this->staff();
    $enrollment = app(Enrollment::class);

    expect(fn () => $enrollment->startSms('staff', $staff, '0612345678'))->toThrow(InvalidArgumentException::class);

    $enrollment->startSms('staff', $staff, '+31 6 1234 5678');

    expect(Mfa::available('staff', $staff))->not->toContain('sms')
        ->and(Challenge::query()->sole()->recipient)->toBe('+31612345678')
        ->and($enrollment->confirmSms('staff', $staff, 'nope'))->toBeFalse();
});

it('sends a login code by text message through the chosen driver', function (string $driver, string $url) {
    config([
        'mfa.sms.driver' => $driver,
        'mfa.sms.drivers.bird' => ['access_key' => 'key', 'channel_id' => 'ch', 'workspace_id' => 'ws'],
        'mfa.sms.drivers.messagebird' => ['access_key' => 'key', 'originator' => 'Login'],
        'mfa.guards.staff.channels' => ['sms'],
    ]);
    Http::fake(['*' => Http::response([], 202)]);

    $staff = $this->staff();
    Factor::query()->create([
        'authenticatable_type' => $staff->getMorphClass(),
        'authenticatable_id' => (string) $staff->id,
        'type' => Factor::SMS,
        'secret' => '+31612345678',
        'confirmed_at' => now(),
    ]);

    Mfa::request('staff', 'jan@example.com');

    Http::assertSent(fn ($request) => $request->url() === $url
        && $request->hasHeader('Authorization', 'AccessKey key')
        && str_contains($request->body(), '31612345678'));

    expect(Challenge::query()->sole()->token)->toBeNull();
})->with([
    'messagebird' => ['messagebird', 'https://rest.messagebird.com/messages'],
    'bird' => ['bird', 'https://api.bird.com/workspaces/ws/channels/ch/messages'],
]);

it('reports a refused text message and still answers the same', function () {
    config(['mfa.sms.driver' => 'messagebird', 'mfa.sms.drivers.messagebird.access_key' => 'key', 'mfa.guards.staff.channels' => ['sms']]);
    Http::fake(['*' => Http::response([], 401)]);

    $staff = $this->staff();
    Factor::query()->create([
        'authenticatable_type' => $staff->getMorphClass(),
        'authenticatable_id' => (string) $staff->id,
        'type' => Factor::SMS,
        'secret' => '+31612345678',
        'confirmed_at' => now(),
    ]);

    Mfa::request('staff', 'jan@example.com');

    Http::assertSentCount(1);
});
