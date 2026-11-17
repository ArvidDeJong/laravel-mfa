<?php

namespace Darvis\Mfa\Tests;

use Darvis\Mfa\MfaServiceProvider;
use Darvis\Mfa\Tests\Fixtures\Staff;
use Darvis\Mfa\Tests\Fixtures\User;
use Flux\FluxServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        // Livewire and Flux, like a host that uses the screens.
        return [LivewireServiceProvider::class, FluxServiceProvider::class, MfaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
        $app['config']->set('mfa.ui.layout', 'layouts.app');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // A host with two session guards: site users and CMS staff.
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.providers.staff', ['driver' => 'eloquent', 'model' => Staff::class]);
        $app['config']->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'staff']);

        $app['config']->set('mfa.guards.staff', [
            'channels' => ['mail', 'sms', 'authenticator', 'recovery'],
            'home' => '/cms',
            'identifier' => 'email',
            'remember' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['2026_11_13_000000_create_mfa_challenges_table', '2026_11_13_000001_create_mfa_factors_table', '2026_11_13_000002_create_mfa_login_attempts_table', '2026_11_13_000003_create_mfa_firewall_rules_table'] as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration.'.php')->up();
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function staff(string $email = 'jan@example.com', bool $active = true): Staff
    {
        return Staff::query()->create(['email' => $email, 'password' => 'correct horse', 'is_active' => $active]);
    }

    protected function user(string $email = 'kim@example.com'): User
    {
        return User::query()->create(['email' => $email, 'password' => 'correct horse']);
    }
}
