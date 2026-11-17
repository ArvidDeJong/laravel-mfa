<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Contracts\Channel;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Support\Emergency;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Overview;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Console\Command;

/**
 * Reports, per guard, whether login survives the loss of one outside service
 * and how many users have actually set up a way in that would.
 */
class CheckCommand extends Command
{
    protected $signature = 'mfa:check {--strict : Exit with an error when a guard is at risk}';

    protected $description = 'Check that no guard depends on a single outside service to sign in';

    public function handle(Mfa $mfa, Emergency $emergency): int
    {
        $atRisk = false;
        $rows = [];

        foreach (MfaConfig::guards() as $guard) {
            $channels = $mfa->channels($guard);
            $resilient = $mfa->resilient($guard);
            $atRisk = $atRisk || ! $resilient;

            $rows[] = [
                $guard,
                implode(', ', array_map(fn (Channel $channel) => $channel->name(), $channels)) ?: 'none',
                $resilient ? 'OK' : 'AT RISK',
                $resilient ? $this->fallbackSummary($mfa, $guard, $channels) : 'every channel depends on '.($channels === [] ? 'nothing' : (string) $channels[0]->dependency()),
            ];

            foreach ($channels as $channel) {
                $until = $channel->dependency() === null ? null : $emergency->unavailableUntil($channel->dependency());

                if ($until !== null) {
                    $this->components->warn("Emergency: [{$channel->name()}] is skipped on guard [{$guard}] until {$until->toDateTimeString()}.");
                }
            }
        }

        $this->table(['Guard', 'Channels', 'Status', 'Detail'], $rows);

        if ($rows === []) {
            $this->components->warn('No guards are configured in config/mfa.php.');
        }

        return $atRisk && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * How many users of a guard keep a way in when their first channel fails.
     *
     * @param  list<Channel>  $channels
     */
    private function fallbackSummary(Mfa $mfa, string $guard, array $channels): string
    {
        $provider = $mfa->provider($guard);

        if (! $provider instanceof EloquentUserProvider) {
            return 'user count needs an Eloquent provider';
        }

        $total = 0;
        $covered = 0;

        $overview = $this->laravel->make(Overview::class);

        foreach ($provider->createModel()->newQuery()->lazy() as $user) {
            $total++;

            if ($overview->hasFallback($guard, $user)) {
                $covered++;
            }
        }

        return "{$covered} of {$total} users have a fallback";
    }
}
