<?php

namespace Darvis\Mfa\Tests\Fixtures;

use Darvis\Mfa\Concerns\HasMfa;
use Darvis\Mfa\Contracts\MfaUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A staff member on a second guard, with the MfaUser contract.
 *
 * @property int $id
 * @property string $email
 * @property bool $is_active
 */
class Staff extends Authenticatable implements MfaUser
{
    use HasMfa;

    protected $table = 'staff';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'password' => 'hashed'];
    }
}
