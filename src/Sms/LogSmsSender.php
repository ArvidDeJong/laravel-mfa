<?php

namespace Darvis\Mfa\Sms;

use Darvis\Mfa\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of sending it. For local work and as
 * the safe default: no text message costs money until a driver is chosen.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('MFA text message (log driver, not sent)', ['to' => $to, 'message' => $message]);
    }
}
