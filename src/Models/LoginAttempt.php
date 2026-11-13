<?php

namespace Darvis\Mfa\Models;

use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of the login log.
 *
 * @property int $id
 * @property string $guard
 * @property string|null $authenticatable_type
 * @property string|null $authenticatable_id
 * @property string|null $identifier
 * @property string|null $channel
 * @property string $result
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class LoginAttempt extends Model
{
    use MassPrunable;

    public const SUCCEEDED = 'succeeded';

    public const EMERGENCY = 'emergency';

    public const EMERGENCY_LINK_ISSUED = 'emergency_link_issued';

    public const EXPIRED = 'expired';

    public const INACTIVE = 'inactive';

    public const INVALID = 'invalid';

    public const THROTTLED = 'throttled';

    public const UNKNOWN_ACCOUNT = 'unknown_account';

    public const UPDATED_AT = null;

    protected $table = 'mfa_login_attempts';

    protected $guarded = [];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(MfaConfig::logRetentionDays()));
    }
}
