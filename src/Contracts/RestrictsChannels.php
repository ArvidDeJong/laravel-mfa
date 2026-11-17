<?php

namespace Darvis\Mfa\Contracts;

/**
 * Optional. Implement it on a user model to switch a channel off for one
 * account, such as "this account has a passkey, so the mailed code no longer
 * works".
 *
 * A channel that is not allowed behaves from the outside like an unknown
 * address: nothing is sent and every code fails, so the screen never tells
 * which accounts are restricted. The login log says "not_allowed".
 */
interface RestrictsChannels
{
    public function mfaAllowsChannel(string $channel, string $guard): bool;
}
