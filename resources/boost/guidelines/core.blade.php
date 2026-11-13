## darvis/laravel-mfa

This package handles login for the session guards listed in `config/mfa.php` (key `mfa`): a mailed code and link, text message, authenticator app, recovery codes and password. It works next to Laravel Fortify; do not list a guard here that Fortify already serves.

- Use the `Darvis\Mfa\Facades\Mfa` facade from the application's own login screen. Do not write your own token or code model, mail, rate limiting or login log next to it.
- `Mfa::request()` returns nothing and the login methods return the user or null. Show the same message for every failure; never reveal whether an address has an account.
- Catch `Darvis\Mfa\Exceptions\ThrottledException` (property `seconds`) around every call.

@verbatim
<code-snippet name="Login screen for the staff guard" lang="php">
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Facades\Mfa;

try {
    Mfa::request('staff', $email);                                  // mail or text a code
    $user = Mfa::loginWithCode('staff', $email, $code);             // code from mail or text
    $user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);
    $user = Mfa::loginWithSecret('staff', $email, 'recovery', $code);
} catch (ThrottledException $exception) {
    // "Too many attempts, try again in {$exception->seconds} seconds."
}
</code-snippet>
@endverbatim

- Channel names: `mail`, `sms`, `authenticator`, `recovery`, `password`. `Mfa::available($guard, $user)` lists what a user can use right now; offer only those.
- Let signed-in users set up channels with `Darvis\Mfa\Enrollment`: `startAuthenticator()` then `authenticatorUri()` (show as QR) then `confirmAuthenticator()`; `startSms()` then `confirmSms()`; `generateRecoveryCodes()` (shown once).
- The mailed link is served by the package at `/mfa/link/{token}` and redirects to the guard's `home`. To use a link route of the application instead, call `Mfa::linkUrlUsing()` and `Mfa::loginWithLink()` from that route, and set `link.enabled` to `false` so the package route is not there as a second way in.
- `config/mfa.php` only needs what differs from the defaults: each group is merged with them (`'log' => ['enabled' => false]` keeps `log.retention_days`). `guards` is taken as a whole.
- A used code or link stays in `mfa_challenges` as consumed (`consumed_at`) until `mfa:prune`. In tests, assert that no usable challenge is left (`Challenge::query()->get()->reject->hasExpired()`), not that the table is empty. Schedule `mfa:prune` daily.
- To keep the application's own login log, listen to `Darvis\Mfa\Events\LoginAttempted` and set `log.enabled` to `false`; the event fires either way.
- A model needs nothing extra when it has `email` (and optionally `phone`, `is_active`) attributes. Otherwise implement `Darvis\Mfa\Contracts\MfaUser`, with the `Darvis\Mfa\Concerns\HasMfa` trait.
- Run `php artisan mfa:check` after changing channels. A guard whose channels all depend on mail is reported as AT RISK.
- During a provider outage use `php artisan mfa:emergency mail --until=18:00` and `php artisan mfa:link {guard} {email}`. Never switch the second step off by hand in code.
- Put the `mfa.emergency` middleware on the routes behind the login when `emergency.allow_password_only` is used.
- Text message driver: `MFA_SMS_DRIVER` is `log` (default, sends nothing), `messagebird` or `bird`.
