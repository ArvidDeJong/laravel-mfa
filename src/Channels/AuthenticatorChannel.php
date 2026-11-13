<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\VerifiesSecret;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use PragmaRX\Google2FA\Google2FA;

/**
 * A six digit code from an authenticator app (TOTP). Needs no outside
 * service, so it keeps working through any provider outage.
 */
class AuthenticatorChannel implements VerifiesSecret
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function name(): string
    {
        return 'authenticator';
    }

    public function dependency(): ?string
    {
        return null;
    }

    public function enrolled(Authenticatable $user): bool
    {
        return Factor::confirmed($user, Factor::AUTHENTICATOR)->exists();
    }

    public function verify(Authenticatable $user, string $input): bool
    {
        $factor = Factor::confirmed($user, Factor::AUTHENTICATOR)->first();

        return $factor instanceof Factor && $this->accept($factor, $input);
    }

    /**
     * Check a code against a factor and remember the time step it belongs to,
     * so the same code cannot be used twice.
     */
    public function accept(Factor $factor, string $input): bool
    {
        $code = preg_replace('/\s+/', '', $input) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        // The application clock, not the system clock, so time travel in
        // tests and a frozen clock behave like everything else in Laravel.
        $now = intdiv(now()->getTimestamp(), $this->google2fa->getKeyRegeneration());

        $step = $this->google2fa->verifyKeyNewer(
            $factor->secret,
            $code,
            $factor->last_used_step,
            MfaConfig::totpWindow(),
            $now,
        );

        if ($step === false) {
            return false;
        }

        $factor->forceFill([
            'last_used_at' => now(),
            'last_used_step' => is_int($step) ? $step : $now,
        ])->save();

        return true;
    }
}
