<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Challenges
    |--------------------------------------------------------------------------
    |
    | A challenge is one pending login: the code that was mailed or texted and,
    | for mail, the link next to it. Only hashes are stored.
    |
    | send_after_response: the mail or text message goes out once the response
    | is on its way to the browser. The screen then answers just as fast for an
    | address that exists as for one that does not, and a slow mail server does
    | not hold up the login screen. It needs no queue worker. Commands and
    | tests send at once.
    |
    */

    'challenge' => [
        'code_length' => 6,
        'lifetime_minutes' => 15,
        'max_attempts' => 5,
        'send_after_response' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Emergency mode
    |--------------------------------------------------------------------------
    |
    | When a provider is down, mark the service it carries as unavailable until
    | a moment in time. Channels that depend on it are skipped until then. Only
    | users left without any working channel may sign in with their password,
    | and only when "allow_password_only" is on.
    |
    | A date that cannot be read, lies in the past or lies further ahead than
    | "max_hours" is ignored: emergency mode fails closed.
    |
    | Prefer "php artisan mfa:emergency mail --until=18:00" on a server with a
    | cached config; it needs no deploy.
    |
    */

    'emergency' => [
        'allow_password_only' => (bool) env('MFA_EMERGENCY_PASSWORD_ONLY', false),
        'max_hours' => 24,
        'unavailable_until' => [
            'mail' => env('MFA_MAIL_DOWN_UNTIL'),
            'sms' => env('MFA_SMS_DOWN_UNTIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firewall
    |--------------------------------------------------------------------------
    |
    | Decides from where somebody may try to sign in. Off by default. Every
    | request is judged in this order:
    |
    |   1. on the deny list                         refused
    |   2. blocked after too many failed logins     refused
    |   3. on the allow list, or a location         let in, from any country
    |   4. from a country that is not allowed       refused
    |   5. anything else                            let in
    |
    | allow, deny, locations: IP addresses or CIDR ranges that never change.
    | Addresses that come and go (a colleague abroad, an extra office) are
    | rules in the database, see "php artisan mfa:allow-ip".
    |
    | locations: the places where you are on site, such as the office. They
    | are let in like "allow", they open routes behind the "mfa.location"
    | middleware and they are where the "pin" channel works.
    |
    | Addresses in allow and locations are never blocked for failed logins,
    | so one typo cannot lock out a whole office.
    |
    | ipv6_prefix: failed logins from an IPv6 address are counted and blocked
    | for its whole /64, the size of one connection. A single address would be
    | swapped for the next one in a second. 128 goes back to single addresses.
    |
    | countries: ISO codes that may sign in, such as ['NL', 'BE']. Empty means
    | no country filter. A guard can have a list of its own (guards.*.countries),
    | which is how a CMS guard is kept to one country while the visitors of the
    | site sign in from anywhere. A country that cannot be told is refused unless
    | "allow_unknown_country" is on: the filter fails closed.
    |
    | country_driver: database (a local file, needs geoip2/geoip2 and
    | "php artisan mfa:geoip-update"), header (a proxy such as Cloudflare
    | names the country) or none.
    |
    */

    'firewall' => [
        'allow' => [],
        'allow_unknown_country' => false,
        'block_after_attempts' => 5,
        'block_minutes' => 15,
        'countries' => [],
        'country_database' => storage_path('app/mfa/country.mmdb'),
        'country_driver' => env('MFA_COUNTRY_DRIVER', 'database'),
        'country_header' => 'CF-IPCountry',
        'deny' => [],
        'enabled' => (bool) env('MFA_FIREWALL', false),
        'ipv6_prefix' => 64,
        'locations' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | Every guard listed here gets its own set of login channels. Leave out a
    | guard that Laravel Fortify already serves.
    |
    | channels:   mail, sms, authenticator, recovery, password, pin
    | countries:  the country filter of the firewall for this guard alone, such
    |             as ['NL'] for a CMS; [] switches the filter off for this
    |             guard, null follows firewall.countries
    | home:       where a mailed link lands after a successful login
    | identifier: the column a visitor types to say who they are
    | layout:     the Blade layout of this guard's login and settings screens
    |             (the Livewire components), such as 'layouts::cms-auth';
    |             null falls back to ui.layout
    | remember:   keep the user signed in with a remember cookie
    |
    */

    'guards' => [
        'web' => [
            'channels' => ['mail', 'authenticator', 'recovery'],
            'countries' => null,
            'home' => '/',
            'identifier' => 'email',
            'layout' => null,
            'remember' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailed link
    |--------------------------------------------------------------------------
    |
    | With "confirm" on, opening the link shows a button and only the button
    | signs in. Mail scanners that open every link then cannot burn the link
    | before its owner gets to it.
    |
    | Turn "enabled" off when the application lands the link on a route of
    | its own (Mfa::linkUrlUsing()): the package routes are then not
    | registered at all.
    |
    */

    'link' => [
        'confirm' => true,
        'enabled' => true,
        'middleware' => ['web'],
        'prefix' => 'mfa',
    ],

    /*
    |--------------------------------------------------------------------------
    | Login log
    |--------------------------------------------------------------------------
    */

    'log' => [
        'enabled' => true,
        'retention_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | PIN
    |--------------------------------------------------------------------------
    |
    | The "pin" channel only works on a location (see the firewall): a short
    | code is fine at the office and nowhere else. After "max_attempts" wrong
    | tries the PIN is locked until the user sets a new one.
    |
    */

    'pin' => [
        'max_attempts' => 4,
        'min_length' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | attempts, decay_seconds: per action, guard, identifier and IP address.
    |
    | account_attempts, account_decay_seconds: wrong codes and secrets for one
    | account, counted over every address together, so a guess spread over a
    | thousand addresses stops as well. Asking for a mail and opening its link
    | are never held back by it: somebody who is being attacked still gets in
    | through the link. 0 switches it off.
    |
    */

    'rate_limit' => [
        'account_attempts' => 20,
        'account_decay_seconds' => 900,
        'attempts' => 5,
        'decay_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Text messages
    |--------------------------------------------------------------------------
    |
    | driver: log, messagebird (rest.messagebird.com), bird (api.bird.com) or
    | a driver of your own, registered with Sms::extend().
    |
    */

    'sms' => [
        'driver' => env('MFA_SMS_DRIVER', 'log'),
        'drivers' => [
            'bird' => [
                'access_key' => env('BIRD_ACCESS_KEY'),
                'channel_id' => env('BIRD_CHANNEL_ID'),
                'workspace_id' => env('BIRD_WORKSPACE_ID'),
            ],
            'messagebird' => [
                'access_key' => env('MESSAGEBIRD_ACCESS_KEY'),
                'originator' => env('MESSAGEBIRD_ORIGINATOR', 'Login'),
            ],
        ],
        'max_per_session' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticator app
    |--------------------------------------------------------------------------
    */

    'totp' => [
        'issuer' => env('APP_NAME', 'Laravel'),
        'window' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Screens
    |--------------------------------------------------------------------------
    |
    | The Livewire components (login, settings, firewall, login log) are only
    | registered when Livewire is installed. "layout" is the Blade layout they
    | render in when a guard names none; null leaves it to Livewire's own
    | default layout.
    |
    | The firewall and login log screens ask the "manageMfa" gate. Without
    | that gate they only open in the local environment.
    |
    */

    'ui' => [
        'layout' => null,
    ],

];
