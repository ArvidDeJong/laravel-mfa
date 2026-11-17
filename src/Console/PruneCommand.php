<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'mfa:prune';

    protected $description = 'Remove expired and used challenges, and login log lines and ended firewall rules past their retention';

    public function handle(): int
    {
        $challenges = Challenge::query()
            ->where(fn ($query) => $query->where('expires_at', '<', now())->orWhereNotNull('consumed_at'))
            ->delete();
        $attempts = (new LoginAttempt)->prunable()->delete();
        // A rule that ended stays for as long as the login log, so it can
        // still be seen who was blocked or let in, and who lifted it.
        $cutoff = now()->subDays(MfaConfig::logRetentionDays());
        $rules = FirewallRule::query()
            ->where(fn ($query) => $query->where('lifted_at', '<', $cutoff)->orWhere(
                fn ($query) => $query->whereNull('lifted_at')->where('expires_at', '<', $cutoff)
            ))
            ->delete();

        $this->components->info("Removed {$challenges} expired or used challenges, {$attempts} log lines and {$rules} firewall rules that ended long ago.");

        return self::SUCCESS;
    }
}
