<?php

namespace Darvis\Mfa\Contracts;

use Darvis\Mfa\Exceptions\SmsException;

interface SmsSender
{
    /**
     * Send one text message.
     *
     * @param  string  $to  The number in international format, such as +31612345678.
     *
     * @throws SmsException When the provider did not accept the message.
     */
    public function send(string $to, string $message): void;
}
