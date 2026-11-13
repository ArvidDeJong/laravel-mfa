# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Login channels per guard, configured in `config/mfa.php`: `mail` (one mail with a code and a link), `sms`, `authenticator` (TOTP), `recovery` and `password`.
- `Mfa` facade: `request()`, `loginWithCode()`, `loginWithSecret()`, `loginWithLink()`, `available()`, `resilient()` and `passwordOnlyAllowed()`.
- `Enrollment` service to set up an authenticator app, a phone number and recovery codes; a factor only counts once it is confirmed.
- Text message drivers `log`, `messagebird` (rest.messagebird.com) and `bird` (api.bird.com), plus `SmsManager::extend()` for your own.
- Emergency mode: declare a service down until a moment in time through config or `php artisan mfa:emergency`. It fails closed, is capped by `emergency.max_hours`, and password only login is limited to users without any working channel.
- `mfa.emergency` middleware that ends an emergency session once the emergency is over.
- `php artisan mfa:check` reports per guard whether login survives the loss of one outside service; `--strict` fails for use in CI.
- `php artisan mfa:link` prints a one time login link for a user who cannot be reached.
- `php artisan mfa:prune` removes expired challenges and old login log lines.
- Login log in `mfa_login_attempts` and the `LoginAttempted` and `ChallengeSent` events.
- Mailed links show a confirm button by default, so mail scanners cannot use a link up.
- Translations in English and Dutch.
