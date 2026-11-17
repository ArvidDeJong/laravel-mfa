<?php

namespace Darvis\Mfa\Firewall;

use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides from where somebody may try to sign in.
 *
 * The order is fixed, see config/mfa.php: the deny list, the block for too
 * many failed logins, the allow list and locations, the country filter.
 * Only the address Laravel reports for the request counts, so the trusted
 * proxies of the application decide what that is; a visitor cannot pick an
 * address with a header.
 */
class Firewall
{
    /** @var array<string, Collection<int, FirewallRule>> */
    private array $rules = [];

    public function __construct(private readonly CountryManager $countries) {}

    public function enabled(): bool
    {
        return MfaConfig::firewallEnabled();
    }

    public function allows(?string $ip, ?string $guard = null): bool
    {
        return $this->decide($ip, $guard)->allowed;
    }

    /**
     * With $ignoreBlock the block for failed logins is left out: that block
     * stops new logins, it does not end the session of somebody who is
     * already signed in from the same address.
     */
    public function decide(?string $ip, ?string $guard = null, bool $ignoreBlock = false): Verdict
    {
        if (! $this->enabled()) {
            return Verdict::allow();
        }

        if ($ip === null || $ip === '') {
            return Verdict::refuse(Verdict::DENIED);
        }

        if ($this->inList('deny', $ip) || $this->matching($ip, $guard, FirewallRule::DENY, automatic: false)->isNotEmpty()) {
            return Verdict::refuse(Verdict::DENIED);
        }

        $block = $ignoreBlock || $this->neverBlocked($ip, $guard) ? null : $this->matching($ip, $guard, FirewallRule::DENY, automatic: true)->first();

        if ($block instanceof FirewallRule) {
            return Verdict::refuse(Verdict::TOO_MANY_FAILURES, $block->expires_at);
        }

        if ($this->inList('allow', $ip) || $this->inList('locations', $ip) || $this->matching($ip, $guard, FirewallRule::ALLOW)->isNotEmpty()) {
            return Verdict::allow();
        }

        return $this->countryAllows($ip, $guard) ? Verdict::allow() : Verdict::refuse(Verdict::COUNTRY);
    }

    /**
     * Whether the address is one of the places where you are on site.
     * Works whether or not the firewall itself is switched on.
     */
    public function isLocation(?string $ip, ?string $guard = null): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        return $this->inList('locations', $ip)
            || $this->matching($ip, $guard, FirewallRule::ALLOW)->contains(fn (FirewallRule $rule) => $rule->is_location);
    }

    /**
     * Whether the "pin" channel may be used from this address: a location
     * from the config, or a location rule with PIN login switched on.
     */
    public function pinAllowed(?string $ip, ?string $guard = null): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        return $this->inList('locations', $ip)
            || $this->matching($ip, $guard, FirewallRule::ALLOW)->contains(fn (FirewallRule $rule) => $rule->is_location && $rule->allows_pin);
    }

    /**
     * Whether failed logins never block this address: the fixed allow list
     * and locations, and rules marked "never block".
     */
    public function neverBlocked(string $ip, ?string $guard = null): bool
    {
        return $this->inList('allow', $ip)
            || $this->inList('locations', $ip)
            || $this->matching($ip, $guard, FirewallRule::ALLOW)->contains(fn (FirewallRule $rule) => $rule->never_block);
    }

    /**
     * Count a failed login from an address and block the address once it has
     * failed too often in a row.
     */
    public function registerFailure(?string $ip): void
    {
        if (! $this->enabled() || $ip === null || $ip === '' || $this->neverBlocked($ip)) {
            return;
        }

        $range = self::network($ip);
        $key = $this->failureKey($range);

        RateLimiter::hit($key, MfaConfig::firewallBlockMinutes() * 60);

        if (RateLimiter::attempts($key) < MfaConfig::firewallBlockAfterAttempts()) {
            return;
        }

        RateLimiter::clear($key);

        FirewallRule::query()->create([
            'action' => FirewallRule::DENY,
            'automatic' => true,
            'expires_at' => now()->addMinutes(MfaConfig::firewallBlockMinutes()),
            'note' => 'Too many failed logins',
            'range' => $range,
        ]);

        $this->rules = [];
    }

    /**
     * Lift a block (or end any rule) now, remembering who did. The failures
     * that led to it were already cleared, so the next typo does not block
     * the address again.
     */
    public function lift(FirewallRule $rule, ?string $by = null): void
    {
        $rule->lift($by);

        $this->rules = [];
    }

    /**
     * A successful login from an address clears its failures.
     */
    public function clearFailures(?string $ip): void
    {
        if ($ip !== null && $ip !== '') {
            RateLimiter::clear($this->failureKey(self::network($ip)));
        }
    }

    /**
     * What failed logins from an address are counted and blocked as: the
     * address itself for IPv4, its network for IPv6 (firewall.ipv6_prefix,
     * a /64 by default). One IPv6 connection holds more addresses than
     * anybody can block one by one.
     */
    public static function network(string $ip): string
    {
        $prefix = MfaConfig::firewallIpv6Prefix();
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        // The loopback address stays itself, so a block on a developer's
        // machine reads as ::1.
        if ($packed === false || $prefix >= 128 || $ip === '::1') {
            return $ip;
        }

        $bytes = intdiv($prefix, 8);
        $network = substr($packed, 0, $bytes);

        if ($prefix % 8 !== 0) {
            $network .= chr(ord($packed[$bytes]) & (0xFF << (8 - $prefix % 8) & 0xFF));
        }

        return inet_ntop(str_pad($network, 16, "\0")).'/'.$prefix;
    }

    /**
     * The country an address is in, or null when the firewall is off, no
     * guard filters on country or the country cannot be told.
     */
    public function country(?string $ip): ?string
    {
        return $this->enabled() && MfaConfig::firewallFiltersCountries() ? $this->countries->driver()->country($ip) : null;
    }

    /**
     * Forget the rules read during this request, after adding or removing one.
     */
    public function refresh(): void
    {
        $this->rules = [];
    }

    private function countryAllows(string $ip, ?string $guard): bool
    {
        $allowed = MfaConfig::firewallCountries($guard);

        if ($allowed === []) {
            return true;
        }

        $country = $this->countries->driver()->country($ip);

        return $country === null ? MfaConfig::firewallAllowUnknownCountry() : in_array(strtoupper($country), $allowed, true);
    }

    private function inList(string $list, string $ip): bool
    {
        $ranges = MfaConfig::firewallList($list);

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }

    /**
     * The database rules of one kind that cover an address.
     *
     * @return Collection<int, FirewallRule>
     */
    private function matching(string $ip, ?string $guard, string $action, ?bool $automatic = null): Collection
    {
        $key = (string) $guard;

        $this->rules[$key] ??= FirewallRule::query()->active($guard)->get();

        return $this->rules[$key]->filter(
            fn (FirewallRule $rule) => $rule->action === $action
                && ($automatic === null || $rule->automatic === $automatic)
                && IpUtils::checkIp($ip, $rule->range)
        )->values();
    }

    private function failureKey(string $range): string
    {
        return 'mfa|firewall|'.$range;
    }
}
