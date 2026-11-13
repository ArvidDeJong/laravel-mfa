---
name: laravel-mfa-development
description: Work with darvis/laravel-mfa. Use it to build or migrate a login screen for a guard, add login channels (mail, sms, authenticator, recovery, password), set up enrollment, or handle a provider outage with emergency mode.
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
