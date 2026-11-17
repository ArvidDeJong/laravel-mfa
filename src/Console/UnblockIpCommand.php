<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Models\FirewallRule;
use Illuminate\Console\Command;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Lifts the block that followed failed logins from an address, from the
 * command line: for when the person who would lift it on screen is the one
 * locked out. A rule somebody put on the deny list by hand stays.
 */
class UnblockIpCommand extends Command
{
    protected $signature = 'mfa:unblock-ip {ip : The blocked IP address}';

    protected $description = 'Lift the block for failed logins from an IP address';

    public function handle(Firewall $firewall): int
    {
        $ip = $this->argument('ip');

        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->components->error('Pass an IP address, such as 198.51.100.7.');

            return self::FAILURE;
        }

        $blocks = FirewallRule::query()
            ->active()
            ->where('action', FirewallRule::DENY)
            ->where('automatic', true)
            ->get()
            ->filter(fn (FirewallRule $rule) => IpUtils::checkIp($ip, $rule->range));

        $blocks->each(fn (FirewallRule $rule) => $firewall->lift($rule, 'command line'));

        $this->components->info($blocks->isEmpty()
            ? "{$ip} is not blocked for failed logins."
            : "The block on {$ip} is lifted.");

        return self::SUCCESS;
    }
}
