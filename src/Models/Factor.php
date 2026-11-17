<?php

namespace Darvis\Mfa\Models;

use Darvis\Mfa\Support\Subject;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something a user has set up to sign in with: an authenticator secret, a
 * confirmed phone number or one recovery code. The secret is encrypted.
 *
 * @property int $id
 * @property string $authenticatable_type
 * @property string $authenticatable_id
 * @property string $type
 * @property string $secret
 * @property int $failed_attempts
 * @property int|null $last_used_step
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at
 */
class Factor extends Model
{
    public const AUTHENTICATOR = 'authenticator';

    public const PIN = 'pin';

    public const RECOVERY = 'recovery';

    public const SMS = 'sms';

    protected $table = 'mfa_factors';

    protected $guarded = [];

    protected $hidden = ['secret'];

    /**
     * @return Builder<static>
     */
    public static function owned(Authenticatable $user, string $type): Builder
    {
        return static::query()
            ->where('authenticatable_type', Subject::type($user))
            ->where('authenticatable_id', Subject::id($user))
            ->where('type', $type);
    }

    /**
     * @return Builder<static>
     */
    public static function confirmed(Authenticatable $user, string $type): Builder
    {
        return static::owned($user, $type)->whereNotNull('confirmed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'failed_attempts' => 'integer',
            'last_used_at' => 'datetime',
            'last_used_step' => 'integer',
            'secret' => 'encrypted',
        ];
    }
}
