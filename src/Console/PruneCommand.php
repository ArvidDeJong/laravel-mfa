<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'mfa:prune';

    protected $description = 'Remove expired challenges and login log lines past their retention';

    public function handle(): int
    {
        $challenges = Challenge::query()->where('expires_at', '<', now())->delete();
        $attempts = (new LoginAttempt)->prunable()->delete();

        $this->components->info("Removed {$challenges} expired challenges and {$attempts} log lines.");

        return self::SUCCESS;
    }
}
