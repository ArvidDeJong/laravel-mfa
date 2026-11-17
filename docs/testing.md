---
title: "Testing"
nav_order: 11
description: "Test a Laravel login that uses darvis/laravel-mfa: catch the mailed code with Notification::fake, sign in with it, and test the firewall and a blocked address."
---

# Testing

How to test a login in your own application that uses the package. The examples use Pest.

## Catch the mailed code

The package sends a notification. Fake notifications and read the code from the one that went out. In a test the mail is sent at once, not after the response.

```php
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('signs staff in with the mailed code', function () {
    Notification::fake();

    $staff = Staff::factory()->create(['email' => 'jan@example.com']);

    Mfa::request('staff', 'jan@example.com');

    $code = null;

    Notification::assertSentTo(
        new AnonymousNotifiable,
        LoginCodeNotification::class,
        function (LoginCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        },
    );

    expect(Mfa::loginWithCode('staff', 'jan@example.com', $code)?->is($staff))->toBeTrue();

    $this->assertAuthenticatedAs($staff, 'staff');
});
```

The notification carries `code`, `url` and `minutes`.

When your application sends the mail itself with `Mfa::sendMailUsing()`, fake and assert your own Mailable instead.

## Nothing for an unknown address

```php
it('sends nothing to an address that does not exist', function () {
    Notification::fake();

    Mfa::request('staff', 'nobody@example.com');

    Notification::assertNothingSent();
});
```

## A wrong code

```php
it('refuses a wrong code', function () {
    Notification::fake();
    Staff::factory()->create(['email' => 'jan@example.com']);

    Mfa::request('staff', 'jan@example.com');

    expect(Mfa::loginWithCode('staff', 'jan@example.com', '000000'))->toBeNull();

    $this->assertGuest('staff');
});
```

## The firewall

The firewall is off unless the config says otherwise. Switch it on in the test.

```php
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Firewall\Verdict;
use Darvis\Mfa\Models\FirewallRule;

it('blocks an address after too many wrong passwords', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 3]);

    foreach (range(1, 3) as $try) {
        app(Firewall::class)->registerFailure('203.0.113.9', 'jan@example.com');
    }

    expect(app(Firewall::class)->decide('203.0.113.9', 'staff')->reason)->toBe(Verdict::TOO_MANY_FAILURES);
});
```

After writing a rule directly, call `refresh()`. The firewall keeps the rules for the rest of the request, and a test is one request:

```php
FirewallRule::query()->create(['action' => FirewallRule::ALLOW, 'range' => '203.0.113.9', 'guard' => 'staff']);

app(Firewall::class)->refresh();
```

Tests run from `127.0.0.1`. To make a request come from another address, pass it with the request:

```php
$this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/cms')->assertForbidden();
```

## Time

Use Laravel's time travel for a code that runs out:

```php
$this->travel(16)->minutes();

expect(Mfa::loginWithCode('staff', 'jan@example.com', $code))->toBeNull();
```

## The rate limit between tests

Tries are counted in the cache. With the `array` cache driver, the default in a test, every test starts clean. With another driver, clear the cache in `beforeEach`.

## Fail the build when a guard is at risk

```bash
php artisan mfa:check --strict
```

Add it to CI, so a config change that leaves a guard with only mail does not reach production.
