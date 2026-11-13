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
- `php artisan mfa:prune` removes expired and used challenges and old login log lines.
- Login log in `mfa_login_attempts` and the `LoginAttempted` and `ChallengeSent` events.
- Mailed links show a confirm button by default, so mail scanners cannot use a link up.
- `Mfa::sendMailUsing()` to send the login mail with the application's own Mailable, and `Mfa::linkUrlUsing()` to land the mailed link on a route of the application.
- `link.enabled` (default `true`) and `MfaConfig::linkEnabled()`: switch the package link routes off when the application lands the link on a route of its own. `Mfa::linkUrl()` then throws a `Darvis\Mfa\Exceptions\ConfigurationException` (a `LogicException`) unless `Mfa::linkUrlUsing()` is set. `Mfa::request()` lets that exception through instead of hiding it like a failed delivery, so a login mail without a link route fails loudly.
- A challenge that signed someone in stays behind as used (`consumed_at`) instead of being deleted, so a link that is opened again is logged as `expired` for its owner. Only one request can use a challenge, so a double click cannot sign in twice. A token that never existed is still not logged.
- The host config is merged over the defaults one level deep, per group: a host that sets only `log.enabled` keeps `log.retention_days`. `guards` is taken from the host as a whole, so the default `web` guard does not come back next to a host's own guards.
- Translations in English and Dutch.
