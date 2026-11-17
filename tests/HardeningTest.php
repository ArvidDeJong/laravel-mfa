<?php

declare(strict_types=1);

use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Firewall\Verdict;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Notifications\LoginCodeNotification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Let the next calls come from another address.
 */
function from(string $ip): void
{
    request()->server->set('REMOTE_ADDR', $ip);
}

beforeEach(fn () => Notification::fake());

it('stops guessing at one account from many addresses, and still lets the owner in by link', function () {
    config(['mfa.guards.staff.channels' => ['mail', 'password'], 'mfa.rate_limit.account_attempts' => 3]);
    $staff = $this->staff();

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        from($ip);

        expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong'))->toBeNull();
    }

    from('203.0.113.4');

    expect(fn () => Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->toThrow(ThrottledException::class);

    // Asking for a mail and opening its link are not held back.
    Mfa::request('staff', 'jan@example.com');

    Notification::assertSentOnDemand(LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$url) {
        $url = $notification->url;

        return true;
    });

    $token = basename((string) parse_url((string) $url, PHP_URL_PATH));

    expect(Mfa::loginWithLink($token)?->is($staff))->toBeTrue();
});

it('forgets the wrong tries for an account once its owner signs in', function () {
    config(['mfa.guards.staff.channels' => ['password'], 'mfa.rate_limit.account_attempts' => 3]);
    $this->staff();

    from('203.0.113.1');
    Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong');
    Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong');

    from('203.0.113.2');
    expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'correct horse'))->not->toBeNull();

    Auth::guard('staff')->logout();

    foreach (['203.0.113.3', '203.0.113.4', '203.0.113.5'] as $ip) {
        from($ip);

        expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong'))->toBeNull();
    }
});

it('does not count per account when the account limit is switched off', function () {
    config(['mfa.guards.staff.channels' => ['password'], 'mfa.rate_limit.account_attempts' => 0]);
    $this->staff();

    foreach (range(1, 30) as $host) {
        from('203.0.113.'.$host);

        expect(Mfa::loginWithSecret('staff', 'jan@example.com', 'password', 'wrong'))->toBeNull();
    }
});

it('counts and blocks an IPv6 address as its /64, and an IPv4 address as itself', function () {
    config(['mfa.firewall.enabled' => true, 'mfa.firewall.block_after_attempts' => 2]);
    $firewall = app(Firewall::class);

    expect(Firewall::network('2001:db8:1:2:aaaa::9'))->toBe('2001:db8:1:2::/64')
        ->and(Firewall::network('203.0.113.9'))->toBe('203.0.113.9')
        ->and(Firewall::network('::1'))->toBe('::1');

    // Two failures from two addresses of one connection.
    $firewall->registerFailure('2001:db8:1:2::1');
    $firewall->registerFailure('2001:db8:1:2:aaaa::9');

    expect(FirewallRule::query()->sole()->range)->toBe('2001:db8:1:2::/64')
        ->and($firewall->decide('2001:db8:1:2:ffff::5')->reason)->toBe(Verdict::TOO_MANY_FAILURES)
        ->and($firewall->decide('2001:db8:1:3::1')->allowed)->toBeTrue();

    $this->artisan('mfa:unblock-ip', ['ip' => '2001:db8:1:2::77'])->assertSuccessful();

    expect($firewall->decide('2001:db8:1:2:ffff::5')->allowed)->toBeTrue();
});

it('blocks a single IPv6 address when the prefix is set to 128, and follows another prefix', function () {
    config(['mfa.firewall.ipv6_prefix' => 128]);

    expect(Firewall::network('2001:db8:1:2:aaaa::9'))->toBe('2001:db8:1:2:aaaa::9');

    config(['mfa.firewall.ipv6_prefix' => 56]);

    expect(Firewall::network('2001:db8:1:2ff:aaaa::9'))->toBe('2001:db8:1:200::/56');
});

it('sends the mail after the response on a web request, and at once from a command', function () {
    $this->staff();

    // A test runs from the command line; pretend it is a web request.
    (fn () => $this->isRunningInConsole = false)->call(app());

    Mfa::request('staff', 'jan@example.com');

    Notification::assertNothingSent();

    app()->terminate();

    Notification::assertSentOnDemand(LoginCodeNotification::class);
});

it('sends at once on a web request when sending after the response is switched off', function () {
    config(['mfa.challenge.send_after_response' => false]);
    $this->staff();

    (fn () => $this->isRunningInConsole = false)->call(app());

    Mfa::request('staff', 'jan@example.com');

    Notification::assertSentOnDemand(LoginCodeNotification::class);
});

it('reports a delivery that fails after the response instead of breaking the request', function () {
    $this->staff();
    Mfa::sendMailUsing(fn () => throw new RuntimeException('The mail server is down.'));

    app(ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported) {
        $reported = $exception->getMessage();
    });

    (fn () => $this->isRunningInConsole = false)->call(app());

    Mfa::request('staff', 'jan@example.com');

    expect($reported)->toBeNull();

    app()->terminate();

    Mfa::sendMailUsing(null);

    expect($reported)->toBe('The mail server is down.');
});
