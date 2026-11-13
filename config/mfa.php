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
    */

    'challenge' => [
        'code_length' => 6,
        'lifetime_minutes' => 15,
        'max_attempts' => 5,
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
    | Guards
    |--------------------------------------------------------------------------
    |
    | Every guard listed here gets its own set of login channels. Leave out a
    | guard that Laravel Fortify already serves.
    |
    | channels:   mail, sms, authenticator, recovery, password
    | home:       where a mailed link lands after a successful login
    | identifier: the column a visitor types to say who they are
    | remember:   keep the user signed in with a remember cookie
    |
    */

    'guards' => [
        'web' => [
            'channels' => ['mail', 'authenticator', 'recovery'],
            'home' => '/',
            'identifier' => 'email',
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
    */

    'link' => [
        'confirm' => true,
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
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Per action, guard, identifier and IP address.
    |
    */

    'rate_limit' => [
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

];
