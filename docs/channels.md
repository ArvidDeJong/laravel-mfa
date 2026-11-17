---
title: "Channels"
nav_order: 4
description: "The six ways to sign in with darvis/laravel-mfa: mailed code and link, text message, authenticator app, recovery codes, password and PIN, and how users set each one up."
---

# Channels

A channel is one way to sign in. You choose per guard which ones are allowed, in `guards.<guard>.channels`.

| Channel | What the user does | Depends on | Works during a mail outage |
| --- | --- | --- | --- |
| `mail` | Types the code from a mail, or opens its link | mail | no |
| `sms` | Types the code from a text message | SMS provider | yes |
| `authenticator` | Types the code from an authenticator app | nothing | yes |
| `recovery` | Types one of their recovery codes | nothing | yes |
| `password` | Types their password | nothing | yes |
| `pin` | Types their PIN, on site only | nothing | yes |

A guard is safe against an outage when at least one channel depends on nothing, or when its channels depend on different services. `php artisan mfa:check` tells you.

## Mail

One mail carries a code and a link. Either one signs in, and using one ends the other.

```php
Mfa::request('staff', $email);                        // the first sending channel of the guard
Mfa::request('staff', $email, 'mail');                // this channel

$user = Mfa::loginWithCode('staff', $email, $code);
```

| Setting | Default | Meaning |
| --- | --- | --- |
| `challenge.code_length` | 6 | Digits in the code |
| `challenge.lifetime_minutes` | 15 | How long the code and the link work |
| `challenge.max_attempts` | 5 | Wrong tries before the code is burned |
| `link.confirm` | true | Show a button before signing in, against mail scanners |

A new request replaces the earlier mail, so only the last code works. Only hashes of the code and the link are stored.

To send the mail in your own design, see [Configuration](configuration.md#send-the-mail-yourself).

## Text message

The user confirms a phone number once. After that a code can be sent to it.

```php
use Darvis\Mfa\Enrollment;

$enrollment = app(Enrollment::class);

$enrollment->startSms('staff', $user, '+31612345678');    // texts a code to that number
$enrollment->confirmSms('staff', $user, $code);           // true when the code was right
```

Signing in:

```php
Mfa::request('staff', $email, 'sms');

$user = Mfa::loginWithCode('staff', $email, $code);
```

Pick the provider with `MFA_SMS_DRIVER`:

| Driver | Needs |
| --- | --- |
| `log` | Nothing. Writes the message to the log, for development |
| `bird` | `BIRD_ACCESS_KEY`, `BIRD_WORKSPACE_ID`, `BIRD_CHANNEL_ID` |
| `messagebird` | `MESSAGEBIRD_ACCESS_KEY`, and `MESSAGEBIRD_ORIGINATOR` for the sender name |

Another provider is one class that implements `Darvis\Mfa\Contracts\SmsSender`:

```php
use Darvis\Mfa\Sms\SmsManager;

app(SmsManager::class)->extend('twilio', fn () => new TwilioSender);
```

`sms.max_per_session` (3) limits how many text messages one visitor can ask for.

## Authenticator app

The user scans a QR code with an app such as Google Authenticator, Microsoft Authenticator or 1Password, and confirms with a first code.

```php
$factor = $enrollment->startAuthenticator($user);
$uri = $enrollment->authenticatorUri($user, $factor);     // otpauth://totp/... for the QR code

$enrollment->confirmAuthenticator($user, $code);          // true when the code was right
```

The `Settings` screen does this for you and draws the QR code on your own server; the key never leaves it. To draw it yourself, install `bacon/bacon-qr-code` and use `Darvis\Mfa\Support\QrCode`.

Signing in:

```php
$user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);
```

The key is stored encrypted. A code works once: the same code cannot be used a second time. `totp.window` (1) accepts the code of one step before and after, for a clock that runs a little off. `totp.issuer` is the name the app shows.

## Recovery codes

Spare codes for when the phone is lost. Each works once.

```php
$codes = $enrollment->generateRecoveryCodes($user);       // eight plain codes, shown once
```

Show them once and tell the user to print or save them. Only hashes are stored. Generating new codes replaces the old ones.

```php
$user = Mfa::loginWithSecret('staff', $email, 'recovery', $code);
```

## Password

The password the user already has, checked through the guard's own user provider.

```php
$user = Mfa::loginWithSecret('staff', $email, 'password', $password);
```

For a password followed by a second step, see [Password plus a second step](two-step.md).

## PIN

A short code that only works at a location: an address you marked as on site. See [Firewall](firewall.md#locations-and-pin).

```php
$enrollment->setPin($user, '4825');

$user = Mfa::loginWithSecret('staff', $email, 'pin', $pin);
```

A PIN that is too short or easy to guess, such as `1234` or `0000`, is refused. `pin.min_length` is 4. After `pin.max_attempts` (4) wrong tries the PIN is locked until the user sets a new one. Only a hash is stored.

## Removing a factor

```php
use Darvis\Mfa\Models\Factor;

$enrollment->remove($user, Factor::AUTHENTICATOR);        // or Factor::SMS, Factor::RECOVERY, Factor::PIN
```

## The user model

The package works with any Eloquent user model. It reads the `email` and `phone` attributes. When your model names them differently, or has its own idea of an active account, add the trait and the contract and override a method:

```php
use Darvis\Mfa\Concerns\HasMfa;
use Darvis\Mfa\Contracts\MfaUser;

class Staff extends Authenticatable implements MfaUser
{
    use HasMfa;

    public function mfaActive(): bool
    {
        return $this->blocked_at === null;
    }
}
```

An account that is not active cannot sign in, and from the outside that looks the same as an unknown address.
