<?php

declare(strict_types=1);

use Darvis\Mfa\Events\LoginAttempted;
use Darvis\Mfa\Exceptions\ConfigurationException;
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\IssuedChallenge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    Mfa::sendMailUsing(null);
    Mfa::linkUrlUsing(null);
});

it('lets the application send the login mail itself', function () {
    Notification::fake();
    $staff = $this->staff();
    $sent = [];

    Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued) use (&$sent) {
        $sent = [$user, $issued->code, $issued->url(), $issued->minutes()];
    });

    Mfa::request('staff', 'jan@example.com');

    Notification::assertNothingSent();

    expect($sent[0]->is($staff))->toBeTrue()
        ->and($sent[1])->toMatch('/^\d{6}$/')
        ->and($sent[3])->toBe(15)
        ->and(Mfa::loginWithCode('staff', 'jan@example.com', $sent[1]))->not->toBeNull();
});

it('lets the application land the mailed link on a route of its own', function () {
    Route::get('/admin/login/{token}', fn (string $token) => Mfa::loginWithLink($token) === null ? 'expired' : 'in')
        ->middleware('web')
        ->name('cms.login.link');

    $this->staff();
    $url = null;

    Mfa::linkUrlUsing(fn (string $token) => route('cms.login.link', $token));
    Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued) use (&$url) {
        $url = $issued->url();
    });

    Mfa::request('staff', 'jan@example.com');

    expect($url)->toContain('/admin/login/');

    $this->get($url)->assertSee('in');

    expect(Auth::guard('staff')->check())->toBeTrue();

    Auth::guard('staff')->logout();
    $this->get($url)->assertSee('expired');
});

it('logs whether the code or the link was used, and for whom a request was throttled', function () {
    Event::fake([LoginAttempted::class]);
    config(['mfa.rate_limit.attempts' => 1]);
    $staff = $this->staff();
    $code = null;

    Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued) use (&$code) {
        $code = $issued->code;
    });

    Mfa::request('staff', 'jan@example.com');
    Mfa::loginWithCode('staff', 'jan@example.com', $code);

    expect(LoginAttempt::query()->where('result', LoginAttempt::SUCCEEDED)->sole()->method)->toBe(LoginAttempt::METHOD_CODE);

    Event::assertDispatched(LoginAttempted::class, fn (LoginAttempted $event) => $event->succeeded() && $event->method === 'code');

    Mfa::request('staff', 'jan@example.com');

    try {
        Mfa::request('staff', 'jan@example.com');
    } catch (Throwable) {
    }

    expect(LoginAttempt::query()->where('result', LoginAttempt::THROTTLED)->sole()->authenticatable_id)->toBe((string) $staff->id);
});

it('lets one login decide about the remember cookie, and otherwise follows the guard', function () {
    config(['mfa.guards.staff.remember' => false]);
    $staff = $this->staff();
    $code = null;

    Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued) use (&$code) {
        $code = $issued->code;
    });

    Mfa::request('staff', 'jan@example.com');
    Mfa::loginWithCode('staff', 'jan@example.com', $code);

    expect($staff->fresh()->remember_token)->toBeNull();

    Auth::guard('staff')->logout();
    Mfa::request('staff', 'jan@example.com');
    Mfa::loginWithCode('staff', 'jan@example.com', $code, true);

    expect($staff->fresh()->remember_token)->not->toBeNull();
});

it('marks what happened while asking for a code as a request, apart from a login attempt', function () {
    Mfa::request('staff', 'nobody@example.com');
    Mfa::loginWithCode('staff', 'nobody@example.com', '000000');

    expect(LoginAttempt::query()->orderBy('id')->pluck('method')->all())
        ->toBe([LoginAttempt::METHOD_REQUEST, LoginAttempt::METHOD_CODE]);
});

it('registers no link routes when they are switched off, and the own route keeps working', function () {
    config(['mfa.link.enabled' => false]);
    Route::setRoutes(new RouteCollection);
    require packageRoot('routes/web.php');

    Route::get('/admin/login/{token}', fn (string $token) => Mfa::loginWithLink($token) === null ? 'expired' : 'in')
        ->middleware('web')
        ->name('cms.login.link');
    Route::getRoutes()->refreshNameLookups();

    $this->staff();
    $url = null;

    Mfa::linkUrlUsing(fn (string $token) => route('cms.login.link', $token));
    Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued) use (&$url) {
        $url = $issued->url();
    });

    Mfa::request('staff', 'jan@example.com');
    $token = basename((string) parse_url((string) $url, PHP_URL_PATH));

    expect(Route::has('mfa.link'))->toBeFalse()
        ->and(Route::has('mfa.link.confirm'))->toBeFalse();

    $this->get('/mfa/link/'.$token)->assertNotFound();
    $this->post('/mfa/link/'.$token)->assertNotFound();

    $this->get($url)->assertSee('in');

    expect(Auth::guard('staff')->check())->toBeTrue();
});

it('explains that the link needs a route of the application when the package route is off', function () {
    config(['mfa.link.enabled' => false]);

    expect(fn () => Mfa::linkUrl('token'))->toThrow(LogicException::class, 'Mfa::linkUrlUsing()');
});

it('lets a request fail loudly when the mail cannot carry a link, and still hides failed deliveries', function () {
    config(['mfa.link.enabled' => false]);
    $this->staff();

    expect(fn () => Mfa::request('staff', 'jan@example.com'))
        ->toThrow(ConfigurationException::class, 'Mfa::linkUrlUsing()');

    Exceptions::fake();
    Mfa::linkUrlUsing(fn (string $token) => 'https://example.test/login/'.$token);
    Mfa::sendMailUsing(fn () => throw new InvalidArgumentException('Mailbox unavailable'));

    Mfa::request('staff', 'jan@example.com');

    Exceptions::assertReported(InvalidArgumentException::class);
});
