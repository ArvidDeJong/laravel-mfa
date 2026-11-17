---
title: "From an own country filter, allow list or login block"
description: "Move an application's own geo filter, IP allow list and block after failed logins to the firewall of darvis/laravel-mfa, without changing what the user sees."
nav_order: 2
---

# From an own country filter, allow list or login block

For an application that already keeps people out by address: a country filter on its CMS, a list of addresses that always get in, a block after too many wrong passwords, or all three, written in the application itself.

Written so that an AI coding agent can carry it out step by step. The first pass replaces the engine and keeps the behaviour: signing in itself, the messages, the login log and the env names stay with the application. Do the steps in order and run the check at the end.

## 1. Recognise the situation

This guide applies when `app/` holds one or more of these:

1. a class that looks up the country of an IP address, often with `geoip2/geoip2` and a local `.mmdb` file;
2. a middleware that answers 403 outside some countries;
3. a model for addresses that may always get in, or get in until a date;
4. a model for addresses blocked after failed logins, with a way to lift the block.

Write down, for later steps:

1. the guard the country filter applies to, and the guards the block applies to;
2. every env name and config key involved, with its default per environment;
3. what counts as a failure (often only a wrong password) and the threshold and duration of the block;
4. who may add an exception and who may lift a block;
5. the tests that cover all of this. They are the contract.

## 2. Plan first

Before changing anything, list per existing class whether it stays, becomes a thin layer on the package, or goes. List every test whose expectation would change, with the old and the new expectation, and ask for approval of exactly those. A test may change in how it is written; what it expects may not change without approval.

## 3. Config

Publish nothing. Write a `config/mfa.php` with only what differs; every group is merged with the defaults.

```php
// $list and $countries: the application's own parsing of its comma separated env values.
$geoFilter = (bool) env('CMS_GEO_FILTER', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true));

return [
    'firewall' => [
        'allow' => $list((string) env('CMS_ALLOWED_IPS', '')),
        'block_after_attempts' => 5,
        'block_minutes' => 15,
        'country_database' => storage_path('app/geoip/dbip-country-lite.mmdb'),
        'enabled' => true,
    ],
    'guards' => [
        'staff' => ['channels' => [], 'countries' => $geoFilter ? $countries : []],
    ],
    'link' => ['enabled' => false],
    'log' => ['enabled' => false],
];
```

1. Keep the env names the application already has, so nothing changes on the servers.
2. Keep `firewall.enabled` on, always. It switches the deny list, the block and the country filter together. An existing switch for the country filter fills or empties `guards.<guard>.countries` instead; otherwise switching the filter off also switches the block off.
3. List only the guard that is filtered by country. A guard that is not listed gets no country filter and still gets the deny list and the block.
4. `link.enabled` and `log.enabled` are off while signing in and the login log stay with the application. The guard then has no channels, and `mfa:check` reports it as at risk: do not schedule `mfa:check --strict` yet.

## 4. Replace the engine

| The application has | Becomes |
| --- | --- |
| Country lookup class | `app(CountryManager::class)->driver()->country($ip)`. It never throws; a missing database is reported and reads as no country. |
| "May this address reach the CMS" | `Firewall::decide($ip, $guard, ignoreBlock: true)` |
| "Is this address blocked" on a login screen | `Firewall::decide($ip, $guard)`; the minutes left come from `Verdict::$until` |
| Counting a failure and blocking | `Firewall::registerFailure($ip)` at the place the application counted |
| Lifting a block | `Firewall::lift($rule, $who)` |
| Model for exceptions and model for blocks | `Darvis\Mfa\Models\FirewallRule`: an allow rule for the guard, and the automatic deny rule the firewall writes itself |
| Monthly download command | A class that extends `GeoipUpdateCommand` with the old `$signature`, so the scheduler and the server documentation stay the same |

Keep the application's own middleware for the routes behind the login when it has its own message, writes its own login log or invalidates the session. It asks `decide()` with `ignoreBlock: true`. Otherwise use `mfa.firewall:<guard>`, which does the same.

After writing a `FirewallRule` directly, in code or in a test, call `app(Firewall::class)->refresh()`: the firewall keeps the rules for the rest of the request.

## 5. What behaves differently

Check each of these against the application and decide, with whoever owns it, before building.

1. **The block does not end a session.** A block stops new logins. Behind a login it is left out, so one colleague with wrong passwords does not sign out the office. An application that also refused signed in users on a blocked address changes here.
2. **Fixed addresses are never blocked.** Addresses in `firewall.allow` and `firewall.locations` are let in and are not blocked for failed logins, for every guard. A database rule is only exempt when it is marked never block.
3. **Counting.** Failures are counted in the cache, in a fixed window of `block_minutes` from the first failure, not in a sliding window from a log. It blocks slightly less often, and clearing the cache resets the count.
4. **Order.** Deny list, block, allow list and locations, country. On a login screen ask the country first (`ignoreBlock: true`) when the application showed the country refusal before the block.
5. **Retention.** `mfa:prune` removes a rule `log.retention_days` after it ended. An application with other terms does not schedule `mfa:prune` for the rules: it extends `FirewallRule` with `MassPrunable` and adds that class to its `model:prune`.
6. **Who did it.** `created_by` and `lifted_by` are text, such as an email address, not a foreign key.
7. **Deny list.** A refusal by the deny list, or a request without an address, is a third outcome next to country and block (`Verdict::DENIED`). Give it a message and a result in the application's log.
8. **Missing database.** The package reports a `ConfigurationException` per request while the country database cannot be read. Throttle it where the application throttled its own exception.

## 6. Data

1. Write a migration that copies the exceptions that still hold to `mfa_firewall_rules`: `action` allow, the guard, the note, the end date, and who added it. It runs after the migrations of the package, which sort by their date.
2. Blocks that are running at the switch need not move: they last minutes.
3. Leave the old tables in place for now and drop them in a later release.

## 7. Release

1. Between the deploy and `php artisan migrate` every login asks a table that does not exist yet. Put the application in maintenance mode until the migrations ran.
2. A path repository to a local copy of the package does not deploy. Require a tagged release before going live.

## 8. Check

1. Run the whole test suite, the code style check and static analysis of the application.
2. Add tests for the differences of step 5 that apply: a signed in user on a blocked address keeps working, a blocked address abroad still gets the 403, the block works with the country filter off, a fixed address is never blocked, an address on the deny list is refused.
3. By hand, on a local copy: five wrong passwords and the message with the minutes, the same message on every other guard, lifting the block on screen, adding and removing an exception, and `php artisan mfa:unblock-ip` for an address that locked itself out.

## Later

Once the engine is replaced and live, the next passes move signing in itself: the mailed code and link (`Mfa::request()`, `Mfa::loginWithCode()`), then the password as a first step (`Mfa::checkSecret()`). See the other guides in this folder.
