<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\SendsChallenge;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Darvis\Mfa\Support\IssuedChallenge;
use Darvis\Mfa\Support\Subject;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;

/**
 * A mail holding a code and a link; either signs in, both die after one use.
 */
class MailChannel implements SendsChallenge
{
    public function name(): string
    {
        return 'mail';
    }

    public function dependency(): ?string
    {
        return 'mail';
    }

    public function enrolled(Authenticatable $user): bool
    {
        return Subject::email($user) !== null;
    }

    public function withLink(): bool
    {
        return true;
    }

    public function send(Authenticatable $user, IssuedChallenge $issued): void
    {
        if (Mfa::mailSender() !== null) {
            (Mfa::mailSender())($user, $issued);

            return;
        }

        $email = $issued->challenge->recipient ?? Subject::email($user);

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)->notify(
            new LoginCodeNotification($issued->code, $issued->url(), $issued->minutes())
        );
    }
}
