<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Mfa;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Subject;
use Illuminate\Console\Command;

/**
 * Prints a one time login link for one user, for the day nothing else
 * reaches them. Hand it over by phone or another channel you trust.
 */
class LinkCommand extends Command
{
    protected $signature = 'mfa:link
        {guard : The guard to sign in on}
        {identifier : The email address (or other identifier) of the user}
        {--minutes=15 : How long the link stays valid}';

    protected $description = 'Create a one time login link for a user who cannot be reached';

    public function handle(Mfa $mfa): int
    {
        $guard = $this->argument('guard');
        $identifier = $this->argument('identifier');

        if (! is_string($guard) || ! is_string($identifier)) {
            return self::FAILURE;
        }

        if (! MfaConfig::hasGuard($guard)) {
            $this->components->error("Guard [{$guard}] is not configured in config/mfa.php.");

            return self::FAILURE;
        }

        $user = $mfa->findUser($guard, $identifier);

        if ($user === null || ! Subject::active($user)) {
            $this->components->error("No active user [{$identifier}] on guard [{$guard}].");

            return self::FAILURE;
        }

        $minutes = min(60, max(1, (int) $this->option('minutes')));
        $issued = Challenge::issue($guard, $user, 'link', true, null, $minutes);

        $mfa->record($guard, LoginAttempt::EMERGENCY_LINK_ISSUED, 'link', $identifier, $user);

        $this->components->info("Valid for {$minutes} minutes, for one login:");
        $this->line((string) $issued->url());

        return self::SUCCESS;
    }
}
