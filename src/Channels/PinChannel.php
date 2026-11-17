<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\VerifiesSecret;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * A short PIN, for on site only: it exists as a channel solely when the
 * request comes from a location the firewall knows and PIN login is allowed
 * there. Anywhere else the user does not have this channel at all.
 *
 * Too many wrong tries lock the PIN until the user sets a new one.
 */
class PinChannel implements VerifiesSecret
{
    public function __construct(private readonly Firewall $firewall) {}

    public function name(): string
    {
        return 'pin';
    }

    public function dependency(): ?string
    {
        return null;
    }

    public function enrolled(Authenticatable $user): bool
    {
        return $this->firewall->pinAllowed(request()->ip())
            && $this->usable($user) instanceof Factor;
    }

    public function verify(Authenticatable $user, string $input): bool
    {
        $factor = $this->usable($user);

        if (! $factor instanceof Factor || ! $this->firewall->pinAllowed(request()->ip())) {
            return false;
        }

        if ($input !== '' && Hash::check($input, $factor->secret)) {
            $factor->forceFill(['failed_attempts' => 0, 'last_used_at' => now()])->save();

            return true;
        }

        $factor->increment('failed_attempts');

        return false;
    }

    /**
     * The user's PIN, unless it is locked.
     */
    private function usable(Authenticatable $user): ?Factor
    {
        return Factor::confirmed($user, Factor::PIN)
            ->where('failed_attempts', '<', MfaConfig::pinMaxAttempts())
            ->first();
    }
}
