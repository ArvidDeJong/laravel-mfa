<?php

namespace Darvis\Mfa\Sms;

use Darvis\Mfa\Contracts\SmsSender;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Support\Manager;

/**
 * Picks the SMS driver. Register a driver of your own with:
 *
 *     app(SmsManager::class)->extend('twilio', fn () => new TwilioSender);
 *
 * @mixin SmsSender
 */
class SmsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return MfaConfig::smsDriver();
    }

    protected function createLogDriver(): SmsSender
    {
        return new LogSmsSender;
    }

    protected function createMessagebirdDriver(): SmsSender
    {
        return new MessageBirdSmsSender(
            MfaConfig::smsDriverSetting('messagebird', 'access_key'),
            MfaConfig::smsDriverSetting('messagebird', 'originator'),
        );
    }

    protected function createBirdDriver(): SmsSender
    {
        return new BirdSmsSender(
            MfaConfig::smsDriverSetting('bird', 'access_key'),
            MfaConfig::smsDriverSetting('bird', 'workspace_id'),
            MfaConfig::smsDriverSetting('bird', 'channel_id'),
        );
    }
}
