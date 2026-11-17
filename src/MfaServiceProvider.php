<?php

namespace Darvis\Mfa;

use Darvis\Mfa\Channels\AuthenticatorChannel;
use Darvis\Mfa\Channels\MailChannel;
use Darvis\Mfa\Channels\PasswordChannel;
use Darvis\Mfa\Channels\PinChannel;
use Darvis\Mfa\Channels\RecoveryChannel;
use Darvis\Mfa\Channels\SmsChannel;
use Darvis\Mfa\Console\AllowIpCommand;
use Darvis\Mfa\Console\CheckCommand;
use Darvis\Mfa\Console\EmergencyCommand;
use Darvis\Mfa\Console\GeoipUpdateCommand;
use Darvis\Mfa\Console\LinkCommand;
use Darvis\Mfa\Console\PruneCommand;
use Darvis\Mfa\Console\UnblockIpCommand;
use Darvis\Mfa\Firewall\CountryManager;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Http\Middleware\EndEmergencySession;
use Darvis\Mfa\Http\Middleware\EnforceFirewall;
use Darvis\Mfa\Http\Middleware\EnsureLocation;
use Darvis\Mfa\Livewire\FirewallRules;
use Darvis\Mfa\Livewire\Login;
use Darvis\Mfa\Livewire\LoginAttempts;
use Darvis\Mfa\Livewire\Settings;
use Darvis\Mfa\Sms\SmsManager;
use Darvis\Mfa\Support\ChannelRegistry;
use Darvis\Mfa\Support\Emergency;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireManager;

class MfaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig();

        $this->app->singleton(SmsManager::class, fn (Application $app) => new SmsManager($app));
        $this->app->singleton(Emergency::class, fn (Application $app) => new Emergency($app->make('cache.store')));
        $this->app->singleton(CountryManager::class, fn (Application $app) => new CountryManager($app));
        $this->app->singleton(Firewall::class);
        $this->app->singleton(AuthenticatorChannel::class);
        $this->app->singleton(PinChannel::class);
        $this->app->singleton(SmsChannel::class);
        $this->app->singleton(Mfa::class);
        $this->app->singleton(Enrollment::class);

        $this->app->singleton(ChannelRegistry::class, function (Application $app) {
            $registry = new ChannelRegistry;

            $registry->extend('authenticator', fn () => $app->make(AuthenticatorChannel::class));
            $registry->extend('mail', fn () => new MailChannel);
            $registry->extend('password', fn () => new PasswordChannel);
            $registry->extend('pin', fn () => $app->make(PinChannel::class));
            $registry->extend('recovery', fn () => new RecoveryChannel);
            $registry->extend('sms', fn () => $app->make(SmsChannel::class));

            return $registry;
        });
    }

    /**
     * Merge the host config over the defaults one level deep, per group, so a
     * host that sets only "log.enabled" keeps "log.retention_days".
     * mergeConfigFrom() would replace the whole group.
     *
     * "guards" stays entirely the host's: a host that lists only "staff" must
     * not get the default "web" guard back.
     */
    private function mergeConfig(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = require __DIR__.'/../config/mfa.php';
        $host = $config->get('mfa', []);
        $merged = $defaults;

        foreach (is_array($host) ? $host : [] as $group => $value) {
            $merged[$group] = $group !== 'guards' && is_array($value) && is_array($defaults[$group] ?? null)
                ? array_merge($defaults[$group], $value)
                : $value;
        }

        $config->set('mfa', $merged);
    }

    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mfa');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'mfa');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $router->aliasMiddleware('mfa.emergency', EndEmergencySession::class);
        $router->aliasMiddleware('mfa.firewall', EnforceFirewall::class);
        $router->aliasMiddleware('mfa.location', EnsureLocation::class);

        $this->registerScreens();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AllowIpCommand::class,
                CheckCommand::class,
                EmergencyCommand::class,
                GeoipUpdateCommand::class,
                LinkCommand::class,
                PruneCommand::class,
                UnblockIpCommand::class,
            ]);

            $this->publishes([__DIR__.'/../config/mfa.php' => config_path('mfa.php')], 'mfa-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'mfa-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/mfa')], 'mfa-views');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/mfa')], 'mfa-lang');
        }
    }

    /**
     * The Livewire screens are optional: without Livewire the package is the
     * facade, the middleware and the commands.
     */
    private function registerScreens(): void
    {
        if (! class_exists(LivewireManager::class) || ! $this->app->bound(LivewireManager::class)) {
            return;
        }

        Livewire::component('mfa-firewall', FirewallRules::class);
        Livewire::component('mfa-login', Login::class);
        Livewire::component('mfa-login-attempts', LoginAttempts::class);
        Livewire::component('mfa-settings', Settings::class);
    }
}
