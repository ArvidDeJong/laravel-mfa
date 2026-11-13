# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/laravel-mfa` gives every session guard its own set of login channels and keeps login working when an outside service is down. It was started on 13 November 2026, during the worldwide Microsoft 365 outage that stopped magic links and mailed codes.

- Namespace: `Darvis\Mfa\` → `src/`
- Config key and file: `mfa` (`config/mfa.php`), read only through [MfaConfig](src/Support/MfaConfig.php)
- Tables: `mfa_challenges`, `mfa_factors`, `mfa_login_attempts`
- Commands: `mfa:check`, `mfa:emergency`, `mfa:link`, `mfa:prune`
- Status: pre release. Not built yet: Livewire and Flux screens, the `passkey` channel (on `laravel/passkeys`), password plus second step as one flow, the docs site, the install command.

## Architecture

- [Mfa](src/Mfa.php) is the public entry point (facade `Mfa`). It finds the user through the guard's own user provider, so a guard needs no model setting.
- A channel implements [Channel](src/Contracts/Channel.php) and one of two shapes: [SendsChallenge](src/Contracts/SendsChallenge.php) (mail, sms: a code goes out, `loginWithCode()` checks it) or [VerifiesSecret](src/Contracts/VerifiesSecret.php) (authenticator, recovery, password: `loginWithSecret()` checks what the user types). Channels are registered by name in [ChannelRegistry](src/Support/ChannelRegistry.php).
- `Channel::dependency()` names the outside service a channel cannot work without (`mail`, `sms`) or null. Everything about resilience and emergencies is derived from it; never hard code a channel name for that.
- [Emergency](src/Support/Emergency.php) answers "is this dependency declared down, and until when". Two sources: config and the cache (set by `mfa:emergency`). Both go through the same parser, which fails closed.
- [Enrollment](src/Enrollment.php) sets factors up. An unconfirmed factor never counts and never replaces a confirmed one.

## Rules that must not break

1. One answer for every failure. `request()` returns nothing and the login methods return null, whether the account is unknown, inactive, the code wrong or the channel not offered. Never add a return value or exception that tells these apart; the log (`LoginAttempt`) is where the difference lives.
2. Only hashes of codes and link tokens are stored; factor secrets are encrypted. The plain values exist on `IssuedChallenge` only.
3. A challenge belongs to one guard. Every lookup goes through `Challenge::owned($guard, $user)`.
4. A successful login deletes every outstanding challenge of that user on that guard.
5. Password only login needs all of: `emergency.allow_password_only`, a configured channel of that guard being down, and the user having no working channel left. See `Mfa::passwordOnlyAllowed()` and its tests before touching it.
6. The authenticator uses the application clock (`now()`), not the system clock, and remembers the last used time step against replay.

## Tests

Testbench with two session guards, `web` ([User](tests/Fixtures/User.php), no contract) and `staff` ([Staff](tests/Fixtures/Staff.php), with `MfaUser`). Migrations are loaded by hand in [TestCase](tests/TestCase.php): add a new migration to that list.
