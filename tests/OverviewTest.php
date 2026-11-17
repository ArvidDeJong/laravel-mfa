<?php

declare(strict_types=1);

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Livewire\Overview as OverviewScreen;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\Overview;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

it('remembers on a block which accounts were tried', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 3, 'mfa.guards.staff.channels' => ['password'], 'mfa.rate_limit.attempts' => 50]);
    $this->staff();

    Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong');
    Mfa::loginWithSecret('staff', 'Jan@Example.com', 'password', 'wrong');
    Mfa::loginWithSecret('staff', 'nobody@example.com', 'password', 'wrong');

    $block = app(Overview::class)->blocks()->sole();

    expect($block->identifiers)->toBe(['jan@example.com', 'nobody@example.com'])
        ->and($block->expires_at?->isFuture())->toBeTrue();
});

it('still blocks in an application that has not run the new migration yet', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 1]);
    Schema::table('mfa_firewall_rules', fn ($table) => $table->dropColumn('identifiers'));

    app(Firewall::class)->registerFailure('203.0.113.9', 'jan@example.com');

    expect(FirewallRule::query()->sole()->range)->toBe('203.0.113.9');
});

it('tells per guard its channels, its countries and whether login survives an outage', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.guards.staff.countries' => ['NL'], 'mfa.guards.staff.channels' => ['mail']]);

    $guards = collect(app(Overview::class)->guards())->keyBy('guard');

    expect($guards['staff']['channels'])->toBe(['mail'])
        ->and($guards['staff']['countries'])->toBe(['NL'])
        ->and($guards['staff']['resilient'])->toBeFalse()
        ->and($guards['web']['countries'])->toBe([])
        ->and($guards['web']['resilient'])->toBeTrue()
        ->and(app(Overview::class)->firewall()['enabled'])->toBeTrue();
});

it('tells per user which ways in they have set up and whether they have a fallback', function () {
    $jan = $this->staff();
    $this->staff('piet@example.com');

    $enrollment = app(Enrollment::class);
    $enrollment->generateRecoveryCodes($jan);

    $users = collect(app(Overview::class)->users('staff')->items())->keyBy('identifier');

    expect($users['jan@example.com']['channels'])->toBe(['mail', 'recovery'])
        ->and($users['jan@example.com']['fallback'])->toBeTrue()
        ->and($users['piet@example.com']['channels'])->toBe(['mail'])
        ->and($users['piet@example.com']['fallback'])->toBeFalse()
        ->and(app(Overview::class)->users('staff', 'piet')->total())->toBe(1);
});

it('shows the overview screen behind the gate, with the firewall, the block and the users', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.guards.staff.countries' => ['NL']]);
    $this->staff();

    Livewire::test(OverviewScreen::class)->assertForbidden();

    Gate::define('manageMfa', fn ($user = null) => true);

    $block = FirewallRule::query()->create([
        'action' => FirewallRule::DENY,
        'automatic' => true,
        'range' => '203.0.113.9',
        'identifiers' => ['kim@example.com'],
        'expires_at' => now()->addMinutes(15),
    ]);

    $screen = Livewire::test(OverviewScreen::class)
        ->assertSee('NL')
        ->assertSee('203.0.113.9')
        ->assertSee('kim@example.com')
        ->assertDontSee('jan@example.com')
        ->set('usersGuard', 'staff')
        ->assertSee('jan@example.com');

    $screen->call('lift', $block->id)->assertSee('Nobody is blocked');

    expect($block->refresh()->lifted_at)->not->toBeNull();
});

it('lists who is blocked from the command line', function () {
    $this->artisan('mfa:blocked')->expectsOutputToContain('Nobody is blocked')->assertSuccessful();

    FirewallRule::query()->create([
        'action' => FirewallRule::DENY,
        'automatic' => true,
        'range' => '203.0.113.9',
        'identifiers' => ['kim@example.com'],
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->artisan('mfa:blocked')->expectsOutputToContain('kim@example.com')->assertSuccessful();
});
