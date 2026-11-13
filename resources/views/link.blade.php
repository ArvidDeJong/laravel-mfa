<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('mfa::mfa.link_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; display: grid; place-items: center; min-height: 100vh; }
        main { background: #fff; border-radius: 12px; padding: 2rem; max-width: 26rem; width: calc(100% - 2rem); box-sizing: border-box; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.5; margin: 0 0 1.25rem; }
        button { font: inherit; font-weight: 600; background: #18181b; color: #fff; border: 0; border-radius: 8px; padding: .75rem 1.25rem; width: 100%; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        @if ($usable)
            <h1>{{ __('mfa::mfa.link_title') }}</h1>
            <p>{{ __('mfa::mfa.link_intro', ['app' => config('app.name')]) }}</p>
            <form method="POST" action="{{ route('mfa.link.confirm', ['token' => $token]) }}">
                @csrf
                <button type="submit">{{ __('mfa::mfa.link_button') }}</button>
            </form>
        @else
            <h1>{{ __('mfa::mfa.link_expired_title') }}</h1>
            <p>{{ __('mfa::mfa.link_expired') }}</p>
        @endif
    </main>
</body>
</html>
