# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Documentation site in `docs/` for GitHub Pages (Jekyll, Just the Docs): installation, a first login screen, channels, a login in two steps, the firewall, screens for the admin, what to do in an outage, configuration, commands, testing, security, troubleshooting, the migration guides and an FAQ, with `llms.txt` and structured data. `tests/DocsSiteTest.php` keeps the pages in step with the config, the commands and the messages.

### Changed

- The README is the quick start and links to the documentation site.

## [1.2.0] - 2026-11-17

### Added

- `Overview` screen (tag `mfa-overview`): whether the firewall is on, the countries each guard may sign in from, who is blocked right now and before, and per user which ways in they have set up and whether they keep one in an outage. Behind the `manageMfa` gate.
- `Darvis\Mfa\Support\Overview` gives the same answers to an application with an admin of its own: `firewall()`, `guards()`, `blocks()`, `pastBlocks()`, `users()`, `channelsOf()` and `hasFallback()`.
- A block for failed logins remembers which accounts were tried (`mfa_firewall_rules.identifiers`, a new migration). `Firewall::registerFailure()` takes what was typed as a second, optional argument; the firewall screen shows it.
- `php artisan mfa:blocked` lists the addresses that are blocked right now, with the accounts that were tried.
- The README, the Boost guideline and the skill advise to show the firewall, the allowed countries, the blocked addresses and the sign-in methods per user in the application's admin.

## [1.1.0] - 2026-11-17

### Security

- Wrong codes and secrets for one account are now also counted over every address together (`rate_limit.account_attempts`, 20 in `rate_limit.account_decay_seconds`, 15 minutes), so guessing spread over many addresses stops as well. Asking for a mail and opening its link are not held back, so the owner of an account under attack still gets in. Set `account_attempts` to 0 to switch it off.
- Failed logins from an IPv6 address are counted and blocked for its whole /64 (`firewall.ipv6_prefix`), the size of one connection, instead of for the single address. The rate limit follows the same network. IPv4 is unchanged; 128 goes back to single addresses.
- The login mail and text message are sent after the response (`challenge.send_after_response`, on by default), so the screen answers as fast for an address that exists as for one that does not. No queue worker is needed; commands and tests still send at once.

### Changed

- A text message counts towards the session's limit when it is issued, not after its delivery.

## [1.0.0] - 2026-11-17

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
- Login firewall (`firewall.enabled`, off by default): a deny list, an allow list and locations from the config, and rules in the database (`mfa_firewall_rules`) that can end on a date and apply to one guard. Order: deny list, block for failed logins, allow list and locations, country filter. A refused login throws `Darvis\Mfa\Exceptions\BlockedException` and is logged as `blocked_ip`, `blocked_attempts` or `blocked_country`.
- Automatic block of an address after `firewall.block_after_attempts` wrong guesses, for `firewall.block_minutes`; never for addresses in `allow` or `locations` or under a rule marked "never block". Removing the rule lifts the block.
- Country filter (`firewall.countries`) with the drivers `database` (local file, needs `geoip2/geoip2`, filled by `php artisan mfa:geoip-update`), `header` (a proxy such as Cloudflare) and `none`, plus `CountryManager::extend()`. An unknown country is refused unless `firewall.allow_unknown_country` is on. A guard can have a country list of its own (`guards.<guard>.countries`), so a CMS guard is kept to one country while the visitors of the site sign in from anywhere.
- Locations: `firewall.locations` and rules marked as a location. The `mfa.location` middleware opens the routes behind it only on site; `mfa.firewall` refuses a request the firewall does not let in and signs out that guard only, so a login on another guard in the same browser stays. The block for failed logins stops new logins only: it does not end a session that is already signed in from the same address (`Firewall::decide($ip, $guard, ignoreBlock: true)`). A refused link is logged for its owner. Both take an optional guard, such as `mfa.location:staff`.
- `pin` channel: a PIN that only exists as a channel on a location that allows PIN login. Stored hashed, locked after `pin.max_attempts` wrong tries, set with `Enrollment::setPin()`.
- `php artisan mfa:allow-ip` lets an address in from the command line; `mfa:prune` also removes firewall rules that ended longer ago than `log.retention_days`, so a lifted block can still be traced until then.
- Livewire screens, registered only when Livewire is installed: `Login`, `Settings`, `FirewallRules` and `LoginAttempts` (tags `mfa-login`, `mfa-settings`, `mfa-firewall`, `mfa-login-attempts`), built with Flux. The layout comes from `guards.<guard>.layout`, else `ui.layout`, else Livewire's default. The firewall and login log screens ask the `manageMfa` gate and without it only open in the local environment.
- The login log stores the country of an attempt when the country filter is on.
- `php artisan mfa:unblock-ip` lifts the block for failed logins from an address, for whoever locked themselves out; a hand made deny rule stays.
- Optional `liftMfaBlocks` gate: who passes it may lift a block on the firewall screen without managing the rules, and sees only the blocks there.
- Migration guide for an application that already has its own country filter, IP allow list or failed login block (`docs/migrating/own-firewall.md`), and an "Admin screens" chapter in the Boost skill.
- `Mfa::checkSecret()` checks a password, PIN, authenticator or recovery code without signing in, for a login that goes on with a second step such as a mailed code. It is throttled, judged by the firewall, logged (`verified`) and counted towards a block like `loginWithSecret()`.
- `Darvis\Mfa\Contracts\RestrictsChannels`: a user model can switch a channel off for one account, such as "this account has a passkey, so the mailed code no longer works". From the outside it behaves like an unknown address; the log says `not_allowed`.
- Lifting an automatic block keeps the rule with `lifted_at` and `lifted_by`; rules added on the firewall screen record `created_by`. `BlockedException::$until` and `Verdict::$until` say when a block after failed logins ends by itself.
- Translations in English and Dutch.
