<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Models\FirewallRule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lets an address through the firewall from the command line: for when the
 * person who would add the rule on screen is the one locked out.
 */
class AllowIpCommand extends Command
{
    protected $signature = 'mfa:allow-ip
        {ip : An IP address or a CIDR range}
        {--until= : The last day the rule holds, such as 2026-11-25; for ever when left out}
        {--guard= : Only for this guard}
        {--note= : Who it is for and why}
        {--location : Mark the address as a location (on site)}
        {--pin : Allow PIN login from this location}
        {--never-block : Never block this address for failed logins}';

    protected $description = 'Let an IP address through the login firewall, for ever or until a date';

    public function handle(Firewall $firewall): int
    {
        $ip = $this->argument('ip');
        $until = $this->option('until');

        if (! is_string($ip) || ! FirewallRule::isValidRange($ip)) {
            $this->components->error('Pass an IP address or a CIDR range, such as 198.51.100.0/24.');

            return self::FAILURE;
        }

        $expiresAt = null;

        if (is_string($until) && $until !== '') {
            try {
                $expiresAt = Carbon::parse($until)->endOfDay();
            } catch (Throwable) {
                $this->components->error('Pass --until as a date, such as 2026-11-25.');

                return self::FAILURE;
            }

            if ($expiresAt->isPast()) {
                $this->components->error('The end date has already passed.');

                return self::FAILURE;
            }
        }

        $note = $this->option('note');
        $guard = $this->option('guard');

        FirewallRule::query()->create([
            'action' => FirewallRule::ALLOW,
            'allows_pin' => (bool) $this->option('pin'),
            'created_by' => 'command line',
            'expires_at' => $expiresAt,
            'guard' => is_string($guard) && $guard !== '' ? $guard : null,
            'is_location' => (bool) $this->option('location') || (bool) $this->option('pin'),
            'never_block' => (bool) $this->option('never-block'),
            'note' => is_string($note) && $note !== '' ? $note : null,
            'range' => $ip,
        ]);

        $firewall->refresh();

        $this->components->info($expiresAt === null
            ? "{$ip} is let in."
            : "{$ip} is let in until {$expiresAt->toDateString()}.");

        if (! $firewall->enabled()) {
            $this->components->warn('The firewall itself is off (mfa.firewall.enabled). Locations and PIN login work regardless.');
        }

        return self::SUCCESS;
    }
}
