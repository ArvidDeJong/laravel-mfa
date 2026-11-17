<?php

namespace Darvis\Mfa\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One address or range the firewall lets in or refuses, for ever or until a
 * moment in time. Rules that never change can live in config/mfa.php instead.
 *
 * A rule made by the firewall itself after too many failed logins has
 * "automatic" set. Lifting it ends the block and keeps the row, with who
 * lifted it and when, until it is pruned.
 *
 * @property int $id
 * @property string $action
 * @property string $range
 * @property string|null $guard
 * @property string|null $note
 * @property bool $is_location
 * @property bool $allows_pin
 * @property bool $never_block
 * @property bool $automatic
 * @property string|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $lifted_at
 * @property string|null $lifted_by
 */
class FirewallRule extends Model
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    protected $table = 'mfa_firewall_rules';

    protected $guarded = [];

    /**
     * Whether the value is one IPv4 or IPv6 address or a CIDR range of one.
     */
    public static function isValidRange(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $address = (string) $address;

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        return ctype_digit($prefix) && (int) $prefix <= (str_contains($address, ':') ? 128 : 32);
    }

    /**
     * End the rule now and remember who did.
     */
    public function lift(?string $by = null): void
    {
        $this->forceFill(['lifted_at' => now(), 'lifted_by' => $by])->save();
    }

    /**
     * Rules that hold right now, for a guard or for every guard.
     *
     * @param  Builder<FirewallRule>  $query
     * @return Builder<FirewallRule>
     */
    public function scopeActive(Builder $query, ?string $guard = null): Builder
    {
        return $query
            ->whereNull('lifted_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($guard !== null, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull('guard')->orWhere('guard', $guard)
            ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_pin' => 'boolean',
            'automatic' => 'boolean',
            'expires_at' => 'datetime',
            'is_location' => 'boolean',
            'lifted_at' => 'datetime',
            'never_block' => 'boolean',
        ];
    }
}
