# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/laravel-mfa` gives every session guard its own set of login channels and keeps login working when an outside service is down. It was started on 13 November 2026, during the worldwide Microsoft 365 outage that stopped magic links and mailed codes.

- Namespace: `Darvis\Mfa\` → `src/`
- Config key and file: `mfa` (`config/mfa.php`), read only through [MfaConfig](src/Support/MfaConfig.php). `MfaServiceProvider::mergeConfig()` merges the host config per group, one level deep, instead of `mergeConfigFrom()`; `guards` stays entirely the host's.
- `link.enabled` off: `routes/web.php` registers nothing and `Mfa::linkUrl()` needs `Mfa::linkUrlUsing()`, otherwise it throws `ConfigurationException`.
- `Mfa::request()` reports and hides every failure to deliver (rule 1), but rethrows `ConfigurationException`: a setup that cannot work must not look like a quiet mail problem. Only rethrow that class, not every `LogicException`: Symfony's invalid address errors are `InvalidArgumentException`, and rethrowing those would reveal that an account exists.
- Tables: `mfa_challenges`, `mfa_factors`, `mfa_login_attempts`
- Tables also: `mfa_firewall_rules`
- Commands: `mfa:check`, `mfa:emergency`, `mfa:link`, `mfa:prune`, `mfa:allow-ip`, `mfa:unblock-ip`, `mfa:blocked`, `mfa:geoip-update`
- Status: released, first version 1.0.0. Not built yet: the `passkey` channel (on `laravel/passkeys`), password plus second step as one flow, the install command.
- `docs/` is the GitHub Pages site. The migration guides live in `docs/migrating/` under the parent page `docs/migrating.md`. `tests/DocsSiteTest.php` fails when a config key, a command or a quoted message is missing from the pages: update the page in the same commit.

## Architecture

- [Mfa](src/Mfa.php) is the public entry point (facade `Mfa`). It finds the user through the guard's own user provider, so a guard needs no model setting.
- A channel implements [Channel](src/Contracts/Channel.php) and one of two shapes: [SendsChallenge](src/Contracts/SendsChallenge.php) (mail, sms: a code goes out, `loginWithCode()` checks it) or [VerifiesSecret](src/Contracts/VerifiesSecret.php) (authenticator, recovery, password: `loginWithSecret()` checks what the user types). Channels are registered by name in [ChannelRegistry](src/Support/ChannelRegistry.php).
- `Channel::dependency()` names the outside service a channel cannot work without (`mail`, `sms`) or null. Everything about resilience and emergencies is derived from it; never hard code a channel name for that.
- [Emergency](src/Support/Emergency.php) answers "is this dependency declared down, and until when". Two sources: config and the cache (set by `mfa:emergency`). Both go through the same parser, which fails closed.
- [Enrollment](src/Enrollment.php) sets factors up. An unconfirmed factor never counts and never replaces a confirmed one.

## Firewall, locations and screens

- [Firewall](src/Firewall/Firewall.php) decides in a fixed order: deny list, automatic block, allow list and locations, country filter. `Mfa` asks it at the start of `request()` and every `loginWith…()` and throws `BlockedException`; a refusal says nothing about an account, so it may be shown. Wrong guesses (`invalid`, `unknown_account`, never a request) count towards an automatic block, which is a `FirewallRule` with `automatic` set.
- A location (config `firewall.locations`, or an allow rule with `is_location`) is what `mfa.location` and the `pin` channel look at. `isLocation()` and `pinAllowed()` work with the firewall switched off.
- Country sources are drivers of [CountryManager](src/Firewall/CountryManager.php) and never throw. An unknown country is refused unless `firewall.allow_unknown_country` is on.
- The Livewire screens in `src/Livewire` are only registered when Livewire is bound. They take their guard from the route default `guard`, keep it `#[Locked]`, and render through `RendersInLayout` (guard layout, else `ui.layout`, else Livewire's default). In Flux components use `:key`, not `wire:key`: Livewire 4 compiles `wire:key` on a Blade component into broken PHP. Clear the error bag at the start of every action, `addError()` messages otherwise survive the next call.
- The login screen offers every channel of the guard to every visitor; only `pin` depends on the address of the request.

- `checkSecret()` shares `verifySecret()` with `loginWithSecret()`: same throttle, firewall, log and failure count, but no login. The application decides what the second step is; the package does not yet enforce "password and a second factor" by itself.
- `RestrictsChannels` is asked in `available()` and again when a code or link is used, so a message sent before the restriction stops working too. A restricted channel must look like an unknown address from the outside.

## Rules that must not break

1. One answer for every failure. `request()` returns nothing and the login methods return null, whether the account is unknown, inactive, the code wrong or the channel not offered. Never add a return value or exception that tells these apart; the log (`LoginAttempt`) is where the difference lives.
2. Only hashes of codes and link tokens are stored; factor secrets are encrypted. The plain values exist on `IssuedChallenge` only.
3. A challenge belongs to one guard. Every lookup goes through `Challenge::owned($guard, $user)`.
4. A successful login marks the challenge it used as consumed (`consumed_at`, atomically through `Challenge::consume()`, so only one request wins) and deletes every other outstanding challenge of that user on that guard. A consumed challenge counts as expired and stays as a tombstone, so a reused link is logged as `expired` for its owner; `mfa:prune` removes it. A new request (`Challenge::issue()`) still deletes all of that user's challenges on that guard. A token that never existed logs nothing.
5. Password only login needs all of: `emergency.allow_password_only`, a configured channel of that guard being down, and the user having no working channel left. See `Mfa::passwordOnlyAllowed()` and its tests before touching it.
6. The authenticator uses the application clock (`now()`), not the system clock, and remembers the last used time step against replay.

## Tests

Testbench with two session guards, `web` ([User](tests/Fixtures/User.php), no contract) and `staff` ([Staff](tests/Fixtures/Staff.php), with `MfaUser`). Migrations are loaded by hand in [TestCase](tests/TestCase.php): add a new migration to that list.
