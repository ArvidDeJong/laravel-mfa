<?php

namespace Darvis\Mfa\Sms;

use Darvis\Mfa\Contracts\SmsSender;
use Darvis\Mfa\Exceptions\SmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Bird Channels API (api.bird.com), for accounts that work with a
 * workspace and an SMS channel.
 */
class BirdSmsSender implements SmsSender
{
    public const ENDPOINT = 'https://api.bird.com/workspaces/%s/channels/%s/messages';

    public function __construct(
        private readonly ?string $accessKey,
        private readonly ?string $workspaceId,
        private readonly ?string $channelId,
    ) {}

    public function send(string $to, string $message): void
    {
        if ($this->accessKey === null || $this->workspaceId === null || $this->channelId === null) {
            throw new SmsException('The bird SMS driver needs an access key, a workspace id and a channel id.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(10)
                ->withHeaders(['Authorization' => 'AccessKey '.$this->accessKey])
                ->post(sprintf(self::ENDPOINT, $this->workspaceId, $this->channelId), [
                    'body' => ['text' => ['text' => $message], 'type' => 'text'],
                    'receiver' => ['contacts' => [['identifierValue' => $to]]],
                ]);
        } catch (ConnectionException $exception) {
            throw new SmsException('Bird could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new SmsException('Bird refused the message (HTTP '.$response->status().').');
        }
    }
}
