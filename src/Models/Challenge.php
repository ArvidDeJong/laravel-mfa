<?php

namespace Darvis\Mfa\Models;

use Darvis\Mfa\Support\IssuedChallenge;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Subject;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A pending login: the code that went out and, optionally, the token behind
 * the link next to it.
 *
 * Only hashes are stored. The plain values exist on the IssuedChallenge that
 * issue() returns, long enough to put them in a message.
 *
 * @property int $id
 * @property string $guard
 * @property string $authenticatable_type
 * @property string $authenticatable_id
 * @property string $channel
 * @property string|null $recipient
 * @property string|null $token
 * @property string $code
 * @property int $attempts
 * @property Carbon $expires_at
 */
class Challenge extends Model
{
    protected $table = 'mfa_challenges';

    protected $guarded = [];

    protected $hidden = ['token', 'code'];

    /**
     * Issue a fresh challenge, replacing every earlier one of this user on
     * this guard: a second message must not leave the first one usable.
     *
     * @param  string|null  $recipient  Where the code goes when that is not the address on file, such as a phone number being confirmed.
     */
    public static function issue(string $guard, Authenticatable $user, string $channel, bool $withLink, ?string $recipient = null, ?int $minutes = null): IssuedChallenge
    {
        static::owned($guard, $user)->delete();

        $length = MfaConfig::codeLength();
        $plainCode = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $plainToken = $withLink ? Str::random(64) : null;

        $challenge = static::query()->create([
            'guard' => $guard,
            'authenticatable_type' => Subject::type($user),
            'authenticatable_id' => Subject::id($user),
            'channel' => $channel,
            'recipient' => $recipient,
            'token' => $plainToken === null ? null : hash('sha256', $plainToken),
            'code' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes($minutes ?? MfaConfig::lifetimeMinutes()),
        ]);

        return new IssuedChallenge($challenge, $plainCode, $plainToken);
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token', hash('sha256', $token))->first();
    }

    public static function findFor(string $guard, Authenticatable $user): ?self
    {
        return static::owned($guard, $user)->latest('id')->first();
    }

    /**
     * @return Builder<static>
     */
    public static function owned(string $guard, Authenticatable $user): Builder
    {
        return static::query()
            ->where('guard', $guard)
            ->where('authenticatable_type', Subject::type($user))
            ->where('authenticatable_id', Subject::id($user));
    }

    /**
     * Expired by the clock, or burned by too many wrong codes.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at->isPast() || $this->attempts >= MfaConfig::maxAttempts();
    }

    /**
     * Check a typed code and count a wrong one, so a code cannot be guessed.
     */
    public function matchesCode(string $code): bool
    {
        if (Hash::check($code, $this->code)) {
            return true;
        }

        $this->increment('attempts');

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
