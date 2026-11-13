<?php

declare(strict_types=1);

use Darvis\Mfa\MfaServiceProvider;
use Darvis\Mfa\Support\MfaConfig;

/**
 * MfaConfig is the one place that reads the package config. These tests guard
 * the things that go wrong once a default is written down twice.
 */
it('returns the values the config file ships', function () {
    $config = require packageRoot('config/mfa.php');

    config(['mfa' => $config]);

    expect(MfaConfig::guards())->toBe(array_keys($config['guards']))
        ->and(MfaConfig::channels('web'))->toBe($config['guards']['web']['channels'])
        ->and(MfaConfig::home('web'))->toBe($config['guards']['web']['home'])
        ->and(MfaConfig::identifier('web'))->toBe($config['guards']['web']['identifier'])
        ->and(MfaConfig::remember('web'))->toBe($config['guards']['web']['remember'])
        ->and(MfaConfig::codeLength())->toBe($config['challenge']['code_length'])
        ->and(MfaConfig::lifetimeMinutes())->toBe($config['challenge']['lifetime_minutes'])
        ->and(MfaConfig::maxAttempts())->toBe($config['challenge']['max_attempts'])
        ->and(MfaConfig::emergencyAllowPasswordOnly())->toBe($config['emergency']['allow_password_only'])
        ->and(MfaConfig::emergencyMaxHours())->toBe($config['emergency']['max_hours'])
        ->and(MfaConfig::emergencyUnavailableUntil('mail'))->toBe($config['emergency']['unavailable_until']['mail'])
        ->and(MfaConfig::linkConfirm())->toBe($config['link']['confirm'])
        ->and(MfaConfig::linkEnabled())->toBe($config['link']['enabled'])
        ->and(MfaConfig::linkMiddleware())->toBe($config['link']['middleware'])
        ->and(MfaConfig::linkPrefix())->toBe($config['link']['prefix'])
        ->and(MfaConfig::logEnabled())->toBe($config['log']['enabled'])
        ->and(MfaConfig::logRetentionDays())->toBe($config['log']['retention_days'])
        ->and(MfaConfig::rateLimitAttempts())->toBe($config['rate_limit']['attempts'])
        ->and(MfaConfig::rateLimitDecaySeconds())->toBe($config['rate_limit']['decay_seconds'])
        ->and(MfaConfig::smsDriver())->toBe($config['sms']['driver'])
        ->and(MfaConfig::smsDriverSetting('messagebird', 'originator'))->toBe($config['sms']['drivers']['messagebird']['originator'])
        ->and(MfaConfig::smsMaxPerSession())->toBe($config['sms']['max_per_session'])
        ->and(MfaConfig::totpIssuer())->toBe($config['totp']['issuer'])
        ->and(MfaConfig::totpWindow())->toBe($config['totp']['window']);
});

it('follows a changed setting', function () {
    config([
        'mfa.challenge.lifetime_minutes' => 5,
        'mfa.guards.staff.home' => '/admin',
        'mfa.link.prefix' => '/login/',
    ]);

    expect(MfaConfig::lifetimeMinutes())->toBe(5)
        ->and(MfaConfig::home('staff'))->toBe('/admin')
        ->and(MfaConfig::linkPrefix())->toBe('login');
});

it('keeps a deliberately empty middleware list empty', function () {
    config(['mfa.link.middleware' => []]);

    expect(MfaConfig::linkMiddleware())->toBe([]);
});

it('treats an empty setting as not set', function () {
    config(['mfa.emergency.unavailable_until.mail' => '  ', 'mfa.sms.drivers.bird.access_key' => '']);

    expect(MfaConfig::emergencyUnavailableUntil('mail'))->toBeNull()
        ->and(MfaConfig::smsDriverSetting('bird', 'access_key'))->toBeNull();
});

/**
 * Merge a host config over the defaults the way the service provider does.
 *
 * @param  array<string, mixed>  $host
 */
function mergedConfig(array $host): void
{
    config(['mfa' => $host]);

    (new MfaServiceProvider(app()))->register();
}

it('keeps the defaults of a group the host sets only partly', function () {
    mergedConfig(['log' => ['enabled' => false]]);

    expect(MfaConfig::logEnabled())->toBeFalse()
        ->and(MfaConfig::logRetentionDays())->toBe(90)
        ->and(MfaConfig::lifetimeMinutes())->toBe(15);
});

it('takes the guards entirely from the host', function () {
    mergedConfig(['guards' => ['staff' => ['channels' => ['mail']]]]);

    expect(MfaConfig::guards())->toBe(['staff'])
        ->and(MfaConfig::hasGuard('web'))->toBeFalse();
});

it('keeps a deliberately empty list of the host', function () {
    mergedConfig(['link' => ['middleware' => []]]);

    expect(MfaConfig::linkMiddleware())->toBe([])
        ->and(MfaConfig::linkPrefix())->toBe('mfa');
});

it('is the only place in the package that reads the config', function () {
    $offenders = [];

    foreach (['src', 'resources', 'routes', 'database'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot($directory)));

        foreach ($files as $file) {
            $relative = str_replace(packageRoot().'/', '', $file->getPathname());

            // The Boost guideline quotes the call it forbids.
            if ($file->getExtension() !== 'php' || str_contains($relative, 'MfaConfig.php') || str_starts_with($relative, 'resources/boost/')) {
                continue;
            }

            if (preg_match("/config\(['\"]mfa\./", (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'these read the config directly instead of through MfaConfig');
});

it('keeps the config keys in alphabetical order, at every level', function () {
    $check = function (array $config, string $path) use (&$check) {
        $keys = array_keys($config);

        if (array_is_list($config)) {
            return;
        }

        $sorted = $keys;
        sort($sorted);

        expect($keys)->toBe($sorted, "the keys in '{$path}' are not in alphabetical order");

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $check($value, trim($path.'.'.$key, '.'));
            }
        }
    };

    $check(require packageRoot('config/mfa.php'), '');
});
