<?php

namespace Darvis\Mfa;

use Darvis\Mfa\Channels\AuthenticatorChannel;
use Darvis\Mfa\Channels\RecoveryChannel;
use Darvis\Mfa\Channels\SmsChannel;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Subject;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Lets a signed-in user set up the channels that need setting up: an
 * authenticator app, a phone number for text messages and recovery codes.
 *
 * A new factor only counts once it is confirmed with a working code, so a
 * typo in a phone number or a failed scan never locks anyone out.
 */
class Enrollment
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuthenticatorChannel $authenticator,
        private readonly SmsChannel $sms,
    ) {}

    /**
     * Start setting up an authenticator app. The current one, if any, keeps
     * working until the new one is confirmed.
     */
    public function startAuthenticator(Authenticatable $user): Factor
    {
        Factor::owned($user, Factor::AUTHENTICATOR)->whereNull('confirmed_at')->delete();

        return Factor::query()->create([
            'authenticatable_type' => Subject::type($user),
            'authenticatable_id' => Subject::id($user),
            'type' => Factor::AUTHENTICATOR,
            'secret' => $this->google2fa->generateSecretKey(32),
        ]);
    }

    /**
     * The otpauth:// URI to show as a QR code.
     */
    public function authenticatorUri(Authenticatable $user, Factor $factor): string
    {
        return $this->google2fa->getQRCodeUrl(
            MfaConfig::totpIssuer(),
            Subject::email($user) ?? Subject::id($user),
            $factor->secret,
        );
    }

    public function confirmAuthenticator(Authenticatable $user, string $code): bool
    {
        $factor = Factor::owned($user, Factor::AUTHENTICATOR)->whereNull('confirmed_at')->latest('id')->first();

        if (! $factor instanceof Factor || ! $this->authenticator->accept($factor, $code)) {
            return false;
        }

        Factor::owned($user, Factor::AUTHENTICATOR)->whereKeyNot($factor->getKey())->delete();

        $factor->forceFill(['confirmed_at' => now()])->save();

        return true;
    }

    /**
     * Start setting up text messages: a code goes to the new number.
     *
     * @param  string  $phone  International format, such as +31612345678.
     *
     * @throws InvalidArgumentException When the number is not in international format.
     */
    public function startSms(string $guard, Authenticatable $user, string $phone): void
    {
        $phone = preg_replace('/[\s().-]+/', '', $phone) ?? '';

        if (preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
            throw new InvalidArgumentException('A phone number must be in international format, such as +31612345678.');
        }

        Factor::owned($user, Factor::SMS)->whereNull('confirmed_at')->delete();

        Factor::query()->create([
            'authenticatable_type' => Subject::type($user),
            'authenticatable_id' => Subject::id($user),
            'type' => Factor::SMS,
            'secret' => $phone,
        ]);

        $this->sms->send($user, Challenge::issue($guard, $user, 'sms', false, $phone));
    }

    public function confirmSms(string $guard, Authenticatable $user, string $code): bool
    {
        $factor = Factor::owned($user, Factor::SMS)->whereNull('confirmed_at')->latest('id')->first();
        $challenge = Challenge::findFor($guard, $user);

        if (! $factor instanceof Factor
            || $challenge === null
            || $challenge->recipient !== $factor->secret
            || $challenge->hasExpired()
            || ! $challenge->matchesCode(trim($code))) {
            return false;
        }

        $challenge->delete();

        Factor::owned($user, Factor::SMS)->whereKeyNot($factor->getKey())->delete();

        $factor->forceFill(['confirmed_at' => now()])->save();

        return true;
    }

    /**
     * Replace the recovery codes and return the new ones. They are shown
     * once; only hashes are kept.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(Authenticatable $user, int $count = 8): array
    {
        Factor::owned($user, Factor::RECOVERY)->delete();

        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = Str::lower(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;

            Factor::query()->create([
                'authenticatable_type' => Subject::type($user),
                'authenticatable_id' => Subject::id($user),
                'type' => Factor::RECOVERY,
                'secret' => RecoveryChannel::hash($code),
                'confirmed_at' => now(),
            ]);
        }

        return $codes;
    }

    /**
     * Remove a factor: Factor::AUTHENTICATOR, Factor::SMS or Factor::RECOVERY.
     */
    public function remove(Authenticatable $user, string $type): void
    {
        Factor::owned($user, $type)->delete();
    }
}
