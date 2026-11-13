<?php

namespace Darvis\Mfa;

use Darvis\Mfa\Channels\AuthenticatorChannel;
use Darvis\Mfa\Channels\MailChannel;
use Darvis\Mfa\Channels\PasswordChannel;
use Darvis\Mfa\Channels\RecoveryChannel;
use Darvis\Mfa\Channels\SmsChannel;
use Darvis\Mfa\Console\CheckCommand;
use Darvis\Mfa\Console\EmergencyCommand;
use Darvis\Mfa\Console\LinkCommand;
use Darvis\Mfa\Console\PruneCommand;
use Darvis\Mfa\Http\Middleware\EndEmergencySession;
use Darvis\Mfa\Sms\SmsManager;
use Darvis\Mfa\Support\ChannelRegistry;
use Darvis\Mfa\Support\Emergency;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class MfaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mfa.php', 'mfa');

        $this->app->singleton(SmsManager::class, fn (Application $app) => new SmsManager($app));
        $this->app->singleton(Emergency::class, fn (Application $app) => new Emergency($app->make('cache.store')));
        $this->app->singleton(AuthenticatorChannel::class);
        $this->app->singleton(SmsChannel::class);
        $this->app->singleton(Mfa::class);
        $this->app->singleton(Enrollment::class);

        $this->app->singleton(ChannelRegistry::class, function (Application $app) {
            $registry = new ChannelRegistry;

            $registry->extend('authenticator', fn () => $app->make(AuthenticatorChannel::class));
            $registry->extend('mail', fn () => new MailChannel);
            $registry->extend('password', fn () => new PasswordChannel);
            $registry->extend('recovery', fn () => new RecoveryChannel);
            $registry->extend('sms', fn () => $app->make(SmsChannel::class));

            return $registry;
        });
    }

    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mfa');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'mfa');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $router->aliasMiddleware('mfa.emergency', EndEmergencySession::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckCommand::class,
                EmergencyCommand::class,
                LinkCommand::class,
                PruneCommand::class,
            ]);

            $this->publishes([__DIR__.'/../config/mfa.php' => config_path('mfa.php')], 'mfa-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'mfa-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/mfa')], 'mfa-views');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/mfa')], 'mfa-lang');
        }
    }
}
