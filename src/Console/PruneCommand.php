<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'mfa:prune';

    protected $description = 'Remove expired and used challenges and login log lines past their retention';

    public function handle(): int
    {
        $challenges = Challenge::query()
            ->where(fn ($query) => $query->where('expires_at', '<', now())->orWhereNotNull('consumed_at'))
            ->delete();
        $attempts = (new LoginAttempt)->prunable()->delete();
        $rules = FirewallRule::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        $this->components->info("Removed {$challenges} expired or used challenges, {$attempts} log lines and {$rules} expired firewall rules.");

        return self::SUCCESS;
    }
}
