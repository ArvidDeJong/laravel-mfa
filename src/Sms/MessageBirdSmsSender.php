<?php

namespace Darvis\Mfa\Sms;

use Darvis\Mfa\Contracts\SmsSender;
use Darvis\Mfa\Exceptions\SmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The classic MessageBird REST API (rest.messagebird.com), for accounts that
 * work with an access key and an originator.
 */
class MessageBirdSmsSender implements SmsSender
{
    public const ENDPOINT = 'https://rest.messagebird.com/messages';

    public function __construct(
        private readonly ?string $accessKey,
        private readonly ?string $originator,
    ) {}

    public function send(string $to, string $message): void
    {
        if ($this->accessKey === null || $this->originator === null) {
            throw new SmsException('The messagebird SMS driver needs an access key and an originator.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->withHeaders(['Authorization' => 'AccessKey '.$this->accessKey])
                ->post(self::ENDPOINT, [
                    'body' => $message,
                    'originator' => $this->originator,
                    'recipients' => ltrim($to, '+'),
                ]);
        } catch (ConnectionException $exception) {
            throw new SmsException('MessageBird could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new SmsException('MessageBird refused the message (HTTP '.$response->status().').');
        }
    }
}
