<?php

declare(strict_types=1);

use Darvis\Mfa\Events\ChallengeSent;
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Request a login mail and return the notification that went out.
 */
function mailedLogin(string $guard, string $email): LoginCodeNotification
{
    Mfa::request($guard, $email);

    $sent = null;

    Notification::assertSentTo(
        new AnonymousNotifiable,
        LoginCodeNotification::class,
        function (LoginCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$sent, $email) {
            $sent = $notification;

            return $notifiable->routes['mail'] === $email;
        },
    );

    return $sent;
}

beforeEach(fn () => Notification::fake());

it('mails a code and a link, and stores only hashes', function () {
    Event::fake([ChallengeSent::class]);
    $this->staff();

    $mail = mailedLogin('staff', 'jan@example.com');
    $challenge = Challenge::query()->sole();

    expect($mail->code)->toMatch('/^\d{6}$/')
        ->and($mail->url)->toContain('/mfa/link/')
        ->and($challenge->code)->not->toBe($mail->code)
        ->and($challenge->token)->not->toBeNull()
        ->and($mail->url)->not->toContain((string) $challenge->token);

    Event::assertDispatched(ChallengeSent::class);
});

it('signs in on the right guard with the mailed code', function () {
    $staff = $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $mail->code)?->is($staff))->toBeTrue()
        ->and(Auth::guard('staff')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(Challenge::query()->count())->toBe(0)
        ->and(LoginAttempt::query()->where('result', LoginAttempt::SUCCEEDED)->where('channel', 'mail')->count())->toBe(1);
});

it('does not accept a code for one guard on another', function () {
    $this->staff('same@example.com');
    $this->user('same@example.com');

    $mail = mailedLogin('staff', 'same@example.com');

    expect(Mfa::loginWithCode('web', 'same@example.com', $mail->code))->toBeNull()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('burns a code after too many wrong tries', function () {
    config(['mfa.challenge.max_attempts' => 2, 'mfa.rate_limit.attempts' => 50]);
    $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');
    $wrong = $mail->code === '000000' ? '111111' : '000000';

    Mfa::loginWithCode('staff', 'jan@example.com', $wrong);
    Mfa::loginWithCode('staff', 'jan@example.com', $wrong);

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $mail->code))->toBeNull()
        ->and(LoginAttempt::query()->where('result', LoginAttempt::EXPIRED)->count())->toBe(1);
});

it('refuses an expired code', function () {
    $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');

    $this->travel(16)->minutes();

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $mail->code))->toBeNull();
});

it('says nothing and sends nothing for an unknown address', function () {
    Mfa::request('staff', 'nobody@example.com');

    Notification::assertNothingSent();

    expect(Challenge::query()->count())->toBe(0)
        ->and(LoginAttempt::query()->where('result', LoginAttempt::UNKNOWN_ACCOUNT)->count())->toBe(1);
});

it('does not let an inactive account in', function () {
    $staff = $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');

    $staff->update(['is_active' => false]);

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $mail->code))->toBeNull()
        ->and(Auth::guard('staff')->check())->toBeFalse();

    Notification::fake();
    Mfa::request('staff', 'jan@example.com');
    Notification::assertNothingSent();
});

it('throttles requests per address', function () {
    config(['mfa.rate_limit.attempts' => 2]);
    $this->staff();

    Mfa::request('staff', 'jan@example.com');
    Mfa::request('staff', 'jan@example.com');

    expect(fn () => Mfa::request('staff', 'jan@example.com'))->toThrow(ThrottledException::class);
});

it('replaces an earlier mail, so only the last code works', function () {
    $this->staff();

    $first = mailedLogin('staff', 'jan@example.com');
    Notification::fake();
    $second = mailedLogin('staff', 'jan@example.com');

    expect(Challenge::query()->count())->toBe(1);

    if ($first->code !== $second->code) {
        expect(Mfa::loginWithCode('staff', 'jan@example.com', $first->code))->toBeNull();
    }

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $second->code))->not->toBeNull();
});

it('shows a confirm button for a mailed link and only signs in on the button', function () {
    $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');

    $this->get($mail->url)->assertOk()->assertSee('Sign in');

    expect(Auth::guard('staff')->check())->toBeFalse();

    $this->post($mail->url)->assertRedirect('/cms');

    expect(Auth::guard('staff')->check())->toBeTrue();

    // One use only.
    Auth::guard('staff')->logout();
    $this->get($mail->url)->assertStatus(410);
    $this->post($mail->url)->assertStatus(410);
});

it('signs in on the link itself when confirming is off', function () {
    config(['mfa.link.confirm' => false]);
    $this->staff();
    $mail = mailedLogin('staff', 'jan@example.com');

    $this->get($mail->url)->assertRedirect('/cms');

    expect(Auth::guard('staff')->check())->toBeTrue();
});

it('answers 410 for a link that never existed', function () {
    $this->get('/mfa/link/'.str_repeat('a', 64))->assertStatus(410);
});
