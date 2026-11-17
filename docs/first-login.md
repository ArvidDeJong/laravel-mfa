---
title: "Your first login screen"
nav_order: 3
description: "Get a working Laravel login with a mailed code and magic link in one route, using the screen of darvis/laravel-mfa, or build your own screen on the Mfa facade."
---

# Your first login screen

There are two ways to a login screen. Pick the first when Livewire and Flux are installed and you want to see it work. Pick the second when the application has its own design or no Livewire.

## With the screen of the package

One route is enough:

```php
use Darvis\Mfa\Livewire\Login;

Route::get('cms/login', Login::class)->defaults('guard', 'staff')->name('cms.login');
```

Open `/cms/login`, type an email address that exists for that guard, and the code and link arrive by mail. Type the code or open the link, and you are signed in and sent to the `home` of the guard.

The screen offers every channel of the guard, and the visitor picks one: a code by mail, the code of an authenticator app, a recovery code. It looks the same for every address, so it does not show which accounts exist or what they have set up.

The guard comes from the route default and cannot be changed by the visitor.

### The layout

Tell each guard which Blade layout its screens render in. Without one, Livewire's default layout is used.

```php
'guards' => [
    'staff' => [
        'channels' => ['mail', 'authenticator', 'recovery'],
        'home' => '/cms',
        'identifier' => 'email',
        'layout' => 'layouts::cms-auth',
        'remember' => true,
    ],
],

'ui' => ['layout' => 'layouts::cms'],
```

`guards.<guard>.layout` wins, then `ui.layout`, then Livewire's default. To change the markup itself, publish the views:

```bash
php artisan vendor:publish --tag=mfa-views
```

### Let users manage their own security

```php
use Darvis\Mfa\Livewire\Settings;

Route::get('cms/security', Settings::class)
    ->defaults('guard', 'staff')
    ->middleware('auth:staff');
```

On this screen a signed in user sets up an authenticator app with a QR code, creates recovery codes and sets a PIN.

## With your own screen

Use the facade from a controller or a Livewire component of your own.

```php
use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Facades\Mfa;

try {
    // Send the mail. Says nothing about whether the address exists.
    Mfa::request('staff', $email);
} catch (ThrottledException $exception) {
    // Too many tries. $exception->seconds says how long to wait.
} catch (BlockedException $exception) {
    // The firewall refused the address. The message may be shown as is.
}
```

On the next screen, sign in with what the user typed:

```php
// The code from the mail or the text message.
$user = Mfa::loginWithCode('staff', $email, $code);

// An authenticator code, a recovery code, a password or a PIN.
$user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);

if ($user === null) {
    // Show one message for every way it can fail.
}
```

Each login method returns the user on success and `null` otherwise. There is one answer for every way it can fail: a wrong code, an expired code, an unknown address and an inactive account all look the same from the outside. Show one message, such as "That did not work. Check what you typed or ask for a new code."

### Which channels to offer

```php
$user = Mfa::findUser('staff', $email);

$channels = $user === null ? [] : Mfa::available('staff', $user);   // such as ['mail', 'authenticator']
```

Do not let the screen look different for an address that does not exist. Offer the mail button to everybody and show the other fields only after a first step, or always.

### The link in the mail

The link is handled by the package at `/mfa/link/{token}`. It shows a button to confirm, signs in and lands on the guard's `home`. The button stops a mail scanner from using up the link before the person does.

To land the link on a route of your own:

```php
// In a service provider.
Mfa::linkUrlUsing(fn (string $token) => route('login.link', $token));

// In that route.
$user = Mfa::loginWithLink($token);
```

Then set `'link' => ['enabled' => false]` in the config, so the package route is not registered at all.

## What to add next

1. A second channel, so the guard is not at risk: see [Channels](channels.md).
2. The firewall, when the login is for staff: see [Firewall](firewall.md).
3. A place in your admin to see who is blocked and what users have set up: see [Screens for your admin](admin.md).
