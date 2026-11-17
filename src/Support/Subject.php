<?php

namespace Darvis\Mfa\Support;

use Darvis\Mfa\Contracts\MfaUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Reads what the package needs from any authenticatable, with or without the
 * MfaUser contract.
 */
class Subject
{
    public static function email(Authenticatable $user): ?string
    {
        $email = $user instanceof MfaUser ? $user->mfaEmail() : data_get($user, 'email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function phone(Authenticatable $user): ?string
    {
        $phone = $user instanceof MfaUser ? $user->mfaPhone() : data_get($user, 'phone');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * Whether the account may sign in at all. A model without an "is_active"
     * attribute is always active.
     */
    public static function active(Authenticatable $user): bool
    {
        if ($user instanceof MfaUser) {
            return $user->mfaActive();
        }

        $flag = data_get($user, 'is_active');

        return $flag === null || (bool) $flag;
    }

    /**
     * Who is acting right now, for "created by" and "lifted by": the address
     * (or id) of whoever is signed in on one of the package's guards.
     */
    public static function actor(): ?string
    {
        foreach (MfaConfig::guards() as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user !== null) {
                return self::email($user) ?? self::id($user);
            }
        }

        return null;
    }

    public static function type(Authenticatable $user): string
    {
        return method_exists($user, 'getMorphClass') ? (string) $user->getMorphClass() : $user::class;
    }

    public static function id(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '';
    }
}
