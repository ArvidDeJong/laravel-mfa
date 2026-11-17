<?php

namespace Darvis\Mfa\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static list<\Darvis\Mfa\Contracts\Channel> channels(string $guard)
 * @method static list<string> available(string $guard, \Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static bool resilient(string $guard)
 * @method static bool passwordOnlyAllowed(string $guard, \Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null findUser(string $guard, string $identifier)
 * @method static void request(string $guard, string $identifier, ?string $channel = null)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null loginWithCode(string $guard, string $identifier, string $code, ?bool $remember = null)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null loginWithSecret(string $guard, string $identifier, string $channel, string $input, ?bool $remember = null)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null checkSecret(string $guard, string $identifier, string $channel, string $input)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null loginWithLink(string $token, ?bool $remember = null)
 * @method static bool linkIsUsable(string $token)
 * @method static void sendMailUsing(?\Closure $callback)
 * @method static void linkUrlUsing(?\Closure $callback)
 * @method static \Illuminate\Contracts\Auth\UserProvider provider(string $guard)
 *
 * @see \Darvis\Mfa\Mfa
 */
class Mfa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Darvis\Mfa\Mfa::class;
    }
}
