<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\Overview;
use Illuminate\Console\Command;

/**
 * Who is blocked for failed logins right now: the address, the accounts that
 * were tried and until when. Lift one with "mfa:unblock-ip".
 */
class BlockedCommand extends Command
{
    protected $signature = 'mfa:blocked';

    protected $description = 'Show the addresses that are blocked for failed logins right now';

    public function handle(Overview $overview): int
    {
        $blocks = $overview->blocks();

        if ($blocks->isEmpty()) {
            $this->components->info('Nobody is blocked for failed logins.');

            return self::SUCCESS;
        }

        $this->table(['Address', 'Country', 'Accounts tried', 'Until'], $blocks->map(fn (FirewallRule $block) => [
            $block->range,
            $overview->country($block) ?? '',
            implode(', ', $block->identifiers ?? []),
            (string) $block->expires_at?->toDateTimeString(),
        ])->all());

        return self::SUCCESS;
    }
}
