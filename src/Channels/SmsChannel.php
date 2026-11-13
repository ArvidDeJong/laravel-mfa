<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\SendsChallenge;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Sms\SmsManager;
use Darvis\Mfa\Support\IssuedChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A code by text message, to a phone number the user confirmed earlier.
 */
class SmsChannel implements SendsChallenge
{
    public function __construct(private readonly SmsManager $sms) {}

    public function name(): string
    {
        return 'sms';
    }

    public function dependency(): ?string
    {
        return 'sms';
    }

    public function enrolled(Authenticatable $user): bool
    {
        return Factor::confirmed($user, Factor::SMS)->exists();
    }

    public function withLink(): bool
    {
        return false;
    }

    public function send(Authenticatable $user, IssuedChallenge $issued): void
    {
        $number = $issued->challenge->recipient ?? Factor::confirmed($user, Factor::SMS)->first()?->secret;

        if (! is_string($number) || $number === '') {
            return;
        }

        $this->sms->driver()->send($number, __('mfa::mfa.sms_message', [
            'app' => config('app.name'),
            'code' => $issued->code,
        ]));
    }
}
