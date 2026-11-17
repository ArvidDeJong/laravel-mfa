---
name: laravel-mfa-development
description: Work with darvis/laravel-mfa. Use it to build or migrate a login screen for a guard, add login channels (mail, sms, authenticator, recovery, password, pin), set up enrollment, add or replace a login firewall (allow and deny list, country filter, block after failed logins) and its admin screens, or handle a provider outage with emergency mode.
---

# darvis/laravel-mfa development

## When to use this skill

Use this skill when an application has `darvis/laravel-mfa` installed and you work on signing in, on a second guard such as `staff`, on setting up an authenticator app or phone number, or when an existing magic link or one time code login has to be replaced by the package.

## Mental model

1. A guard in `config/mfa.php` lists its channels. The package finds users through that guard's own user provider.
2. Sending channels (`mail`, `sms`) put a code in a message: `Mfa::request()` then `Mfa::loginWithCode()`. The mail also carries a link, served by the package.
3. Secret channels (`authenticator`, `recovery`, `password`) check what the user types: `Mfa::loginWithSecret($guard, $identifier, $channel, $input)`.
4. Every channel names the outside service it depends on. `mfa:check` and emergency mode are built on that.

## Migrating an existing login

Follow the guide that matches the application in `docs/migrating/` of the package (also at https://github.com/ArvidDeJong/laravel-mfa/tree/main/docs/migrating). In short:

1. Find the guard that Fortify serves (`config/fortify.php`, key `guard`). Leave that guard alone.
2. For every other session guard that signs in by mail, list it in `config/mfa.php`.
3. Replace the calls into the application's own token or code classes with the `Mfa` facade.
4. Delete the old token model, its migration (add a migration that drops the table), the mail class and the hand written rate limiting.
5. Keeping an own link route or login log: `Mfa::linkUrlUsing()` plus `'link' => ['enabled' => false]`, and a listener on `LoginAttempted` plus `'log' => ['enabled' => false]`. Only set what differs; each config group is merged with the defaults.
6. Schedule `php artisan mfa:prune` daily, then run `php artisan mfa:check` and the application's tests.

Pending tokens need no data migration: they live for minutes.

## Admin screens

With Livewire and Flux installed the package brings two admin screens next to `Login` and `Settings`. Use them unless the application has a reason not to.

| Screen | Class | What it does |
| --- | --- | --- |
| Firewall | `Darvis\Mfa\Livewire\FirewallRules` | Allow or deny an address or CIDR range, for ever or until a date, for every guard or one, mark a location, allow PIN login, never block. Shows the fixed lists from the config and lifts a block. |
| Login log | `Darvis\Mfa\Livewire\LoginAttempts` | The package's own login log (`mfa_login_attempts`), with search and a filter on failures. |

1. Route them behind the login of the admin guard: `Route::get('admin/firewall', FirewallRules::class)`.
2. Define the gate: `Gate::define('manageMfa', fn ($user = null) => ...)`. Without the gate the screens only open in the local environment.
3. Optional: `Gate::define('liftMfaBlocks', ...)` for a wider group that may lift a block and nothing else.
4. Layout: `guards.<guard>.layout`, else `ui.layout`, else Livewire's default. Publish the views (`mfa-views`) only to change markup.
5. Put `mfa.firewall:<guard>` on the routes behind the login and add `EnforceFirewall` to Livewire's persistent middleware.

Keep a screen of the application's own when one of these holds, and build it thin on the same table:

1. the application keeps its own login log (`log.enabled` is false), so the package log is empty;
2. the rights differ per action in a way two gates do not cover;
3. texts or tests of the existing screen are a contract with the client.

In such a screen: create and delete `Darvis\Mfa\Models\FirewallRule` rows, call `app(Firewall::class)->refresh()` after writing one directly (the firewall keeps the rules for the rest of the request, also in tests), lift a block with `Firewall::lift($rule, $who)` and never delete a block, so the row keeps who lifted it.

Locked out: `php artisan mfa:allow-ip` for the country filter, `php artisan mfa:unblock-ip` for the block after wrong passwords.

## Replacing an application's own firewall

An application that already has a country filter, an IP allow list or a block after failed logins moves to the firewall of the package with `docs/migrating/own-firewall.md`. Replace the engine first and keep the behaviour: env names, messages, the application's own middleware and its tests stay.

## Rules

- One message for every failed login. Never branch the UI on why it failed.
- Wrap calls in a try for `ThrottledException`.
- Never store or log a plain code, link, secret or recovery code. Recovery codes are shown once, right after `generateRecoveryCodes()`.
- Do not list `password` for a guard whose password column holds a random hash.

## Testing in the host application

```php
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Notification;

Notification::fake();
Mfa::request('staff', 'jan@example.com');

Notification::assertSentOnDemand(LoginCodeNotification::class, function ($notification) use (&$code) {
    $code = $notification->code;

    return true;
});

expect(Mfa::loginWithCode('staff', 'jan@example.com', $code))->not->toBeNull();

// The used challenge stays behind as consumed: check that none is usable, not that the table is empty.
expect(Challenge::query()->get()->reject->hasExpired())->toBeEmpty();
```
