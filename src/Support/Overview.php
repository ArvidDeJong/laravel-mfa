<?php

namespace Darvis\Mfa\Support;

use Darvis\Mfa\Contracts\Channel;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Models\FirewallRule;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * What an administrator should be able to see about signing in: how every
 * guard is set up, what the firewall lets through, who is blocked right now
 * and which ways in each user has set up.
 *
 * The overview screen is built on it; an application with an admin of its
 * own reads the same answers here.
 */
class Overview
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly Firewall $firewall,
        private readonly Emergency $emergency,
    ) {}

    /**
     * Per guard: its channels, the countries it may sign in from, whether
     * login survives the loss of one outside service, and the channels an
     * emergency skips right now.
     *
     * @return list<array{guard: string, channels: list<string>, countries: list<string>, resilient: bool, skipped: list<string>}>
     */
    public function guards(): array
    {
        $guards = [];

        foreach (MfaConfig::guards() as $guard) {
            $channels = $this->mfa->channels($guard);

            $guards[] = [
                'guard' => $guard,
                'channels' => array_map(fn (Channel $channel) => $channel->name(), $channels),
                'countries' => $this->firewall->enabled() ? MfaConfig::firewallCountries($guard) : [],
                'resilient' => $this->mfa->resilient($guard),
                'skipped' => array_values(array_map(
                    fn (Channel $channel) => $channel->name(),
                    array_filter($channels, fn (Channel $channel) => $this->emergency->isUnavailable($channel->dependency())),
                )),
            ];
        }

        return $guards;
    }

    /**
     * Whether the firewall is on, and the fixed lists from the config.
     *
     * @return array{enabled: bool, allow: list<string>, deny: list<string>, locations: list<string>}
     */
    public function firewall(): array
    {
        return [
            'enabled' => $this->firewall->enabled(),
            'allow' => MfaConfig::firewallList('allow'),
            'deny' => MfaConfig::firewallList('deny'),
            'locations' => MfaConfig::firewallList('locations'),
        ];
    }

    /**
     * The addresses that are blocked for failed logins right now, the one
     * that ends last first. Each rule knows the accounts that were tried
     * (identifiers) and until when it holds.
     *
     * @return Collection<int, FirewallRule>
     */
    public function blocks(): Collection
    {
        return FirewallRule::query()
            ->active()
            ->where('action', FirewallRule::DENY)
            ->where('automatic', true)
            ->orderByDesc('expires_at')
            ->get();
    }

    /**
     * The blocks that ended, by themselves or because somebody lifted them,
     * the latest first. They stay as long as the login log.
     *
     * @return Collection<int, FirewallRule>
     */
    public function pastBlocks(int $limit = 25): Collection
    {
        return FirewallRule::query()
            ->where('action', FirewallRule::DENY)
            ->where('automatic', true)
            ->where(fn (Builder $query) => $query->whereNotNull('lifted_at')->orWhere('expires_at', '<=', now()))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The country a blocked address is in, when a guard filters on country.
     */
    public function country(FirewallRule $rule): ?string
    {
        return $this->firewall->country(explode('/', $rule->range)[0]);
    }

    /**
     * The users of a guard with the ways in each has set up and whether they
     * keep one when a service fails.
     *
     * @return LengthAwarePaginator<int, array{user: Authenticatable, identifier: string, channels: list<string>, fallback: bool}>
     */
    public function users(string $guard, string $search = '', int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $provider = $this->mfa->provider($guard);

        if (! $provider instanceof EloquentUserProvider) {
            return new Paginator([], 0, $perPage);
        }

        $column = MfaConfig::identifier($guard);
        $search = trim($search);

        /** @var Model $model */
        $model = $provider->createModel();

        return $model->newQuery()
            ->when($search !== '', fn (Builder $query) => $query->where($column, 'like', '%'.$search.'%'))
            ->orderBy($column)
            ->paginate($perPage, ['*'], $pageName)
            ->through(fn (Model $user) => $user instanceof Authenticatable ? [
                'user' => $user,
                'identifier' => (string) $user->getAttribute($column),
                'channels' => $this->channelsOf($guard, $user),
                'fallback' => $this->hasFallback($guard, $user),
            ] : []);
    }

    /**
     * The channels of the guard this user has set up, emergency or not.
     *
     * @return list<string>
     */
    public function channelsOf(string $guard, Authenticatable $user): array
    {
        return array_values(array_map(
            fn (Channel $channel) => $channel->name(),
            array_filter($this->mfa->channels($guard), fn (Channel $channel) => $channel->enrolled($user)),
        ));
    }

    /**
     * Whether the user keeps a way in when one outside service fails: a
     * channel that needs none, or channels that lean on different ones.
     */
    public function hasFallback(string $guard, Authenticatable $user): bool
    {
        $dependencies = [];

        foreach ($this->mfa->channels($guard) as $channel) {
            if ($channel->enrolled($user)) {
                $dependencies[] = $channel->dependency();
            }
        }

        return in_array(null, $dependencies, true) || count(array_unique($dependencies)) > 1;
    }
}
