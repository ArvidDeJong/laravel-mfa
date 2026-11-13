<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Exceptions\EmergencyException;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Support\Emergency;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Declares an outside service down until a moment in time, without a deploy.
 */
class EmergencyCommand extends Command
{
    protected $signature = 'mfa:emergency
        {dependency? : The service that is down, such as mail or sms}
        {--until= : When the emergency ends, such as 18:00 or "2026-11-13 18:00"}
        {--off : End the emergency for this service now}';

    protected $description = 'Skip the channels of a service that is down, until a set time';

    public function handle(Mfa $mfa, Emergency $emergency): int
    {
        $dependencies = $this->dependencies($mfa);
        $dependency = $this->argument('dependency');

        if (! is_string($dependency)) {
            return $this->status($emergency, $dependencies);
        }

        if (! in_array($dependency, $dependencies, true)) {
            $this->components->error("No configured channel depends on [{$dependency}]. Known: ".(implode(', ', $dependencies) ?: 'none').'.');

            return self::FAILURE;
        }

        if ($this->option('off')) {
            $emergency->clear($dependency);
            $this->components->info("Emergency for [{$dependency}] ended.");

            if ($emergency->unavailableUntil($dependency) !== null) {
                $this->components->warn('config/mfa.php still declares it down; remove the value from .env as well.');
            }

            return self::SUCCESS;
        }

        $until = $this->option('until');

        if (! is_string($until) || trim($until) === '') {
            $this->components->error('Pass --until with the moment the emergency ends, or --off to end it.');

            return self::FAILURE;
        }

        try {
            $moment = Carbon::parse($until);
            $emergency->declare($dependency, $moment);
        } catch (EmergencyException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error("Could not read [{$until}] as a date and time.");

            return self::FAILURE;
        }

        $this->components->info("Channels that depend on [{$dependency}] are skipped until {$moment->toDateTimeString()}.");

        if (! MfaConfig::emergencyAllowPasswordOnly()) {
            $this->components->warn('Password only login is off (emergency.allow_password_only). Users without another channel stay locked out; use mfa:link to let one in.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $dependencies
     */
    private function status(Emergency $emergency, array $dependencies): int
    {
        $rows = [];

        foreach ($dependencies as $dependency) {
            $until = $emergency->unavailableUntil($dependency);
            $rows[] = [$dependency, $until === null ? 'available' : 'down until '.$until->toDateTimeString()];
        }

        $this->table(['Service', 'Status'], $rows);

        return self::SUCCESS;
    }

    /**
     * The outside services the configured channels lean on.
     *
     * @return list<string>
     */
    private function dependencies(Mfa $mfa): array
    {
        $dependencies = [];

        foreach (MfaConfig::guards() as $guard) {
            foreach ($mfa->channels($guard) as $channel) {
                if ($channel->dependency() !== null) {
                    $dependencies[$channel->dependency()] = true;
                }
            }
        }

        return array_keys($dependencies);
    }
}
