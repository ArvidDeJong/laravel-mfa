<?php

namespace Darvis\Mfa\Tests\Fixtures;

use Darvis\Mfa\Concerns\HasMfa;
use Darvis\Mfa\Contracts\MfaUser;
use Darvis\Mfa\Contracts\RestrictsChannels;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A staff member on a second guard, with the MfaUser and RestrictsChannels
 * contracts.
 *
 * @property int $id
 * @property string $email
 * @property bool $is_active
 */
class Staff extends Authenticatable implements MfaUser, RestrictsChannels
{
    use HasMfa;

    /**
     * Channels the tests switch off for every staff member, such as
     * ['mail'] for "this account has a passkey".
     *
     * @var list<string>
     */
    public static array $restricted = [];

    public function mfaAllowsChannel(string $channel, string $guard): bool
    {
        return ! in_array($channel, self::$restricted, true);
    }

    protected $table = 'staff';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'password' => 'hashed'];
    }
}
