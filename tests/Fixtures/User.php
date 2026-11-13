<?php

namespace Darvis\Mfa\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A plain user model, without the MfaUser contract.
 *
 * @property int $id
 * @property string $email
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
