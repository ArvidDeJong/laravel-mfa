<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\VerifiesSecret;
use Darvis\Mfa\Models\Factor;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One time recovery codes, handed out when a user sets up another channel.
 * Each code works once.
 */
class RecoveryChannel implements VerifiesSecret
{
    public function name(): string
    {
        return 'recovery';
    }

    public function dependency(): ?string
    {
        return null;
    }

    public function enrolled(Authenticatable $user): bool
    {
        return Factor::confirmed($user, Factor::RECOVERY)->exists();
    }

    public function verify(Authenticatable $user, string $input): bool
    {
        $hash = self::hash($input);

        foreach (Factor::confirmed($user, Factor::RECOVERY)->get() as $factor) {
            if (hash_equals($factor->secret, $hash)) {
                $factor->delete();

                return true;
            }
        }

        return false;
    }

    /**
     * Codes are compared without regard to case, spaces or dashes.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', strtolower(preg_replace('/[\s-]+/', '', $code) ?? ''));
    }
}
