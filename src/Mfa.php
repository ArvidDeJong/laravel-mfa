<?php

namespace Darvis\Mfa;

use Closure;
use Darvis\Mfa\Contracts\Channel;
use Darvis\Mfa\Contracts\RestrictsChannels;
use Darvis\Mfa\Contracts\SendsChallenge;
use Darvis\Mfa\Contracts\VerifiesSecret;
use Darvis\Mfa\Events\ChallengeSent;
use Darvis\Mfa\Events\LoginAttempted;
use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Exceptions\ConfigurationException;
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\ChannelRegistry;
use Darvis\Mfa\Support\Emergency;
use Darvis\Mfa\Support\IssuedChallenge;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Subject;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The login layer: which channels a guard offers, sending a challenge and
 * signing in once the user has proven who they are.
 *
 * Every public method answers the same way for an unknown account as for a
 * wrong code, so the outside cannot tell which addresses have an account.
 */
class Mfa
{
    public const SESSION_EMERGENCY = 'mfa.emergency.';

    private const SESSION_SMS_COUNT = 'mfa.sms_sent';

    /** @var (Closure(Authenticatable, IssuedChallenge): void)|null */
    private static ?Closure $mailSender = null;

    /** @var (Closure(string): string)|null */
    private static ?Closure $linkUrlResolver = null;

    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly Emergency $emergency,
        private readonly AuthFactory $auth,
        private readonly Firewall $firewall,
    ) {}

    /**
     * Send the login mail yourself, for example with a Mailable in the
     * application's own design. Pass null to go back to the package mail.
     *
     * @param  (Closure(Authenticatable, IssuedChallenge): void)|null  $callback
     */
    public static function sendMailUsing(?Closure $callback): void
    {
        self::$mailSender = $callback;
    }

    /**
     * Point the mailed link at a route of the application, which then calls
     * loginWithLink() itself. Pass null to go back to the package route.
     *
     * @param  (Closure(string): string)|null  $callback  Receives the plain token, returns the URL.
     */
    public static function linkUrlUsing(?Closure $callback): void
    {
        self::$linkUrlResolver = $callback;
    }

    /**
     * @return (Closure(Authenticatable, IssuedChallenge): void)|null
     */
    public static function mailSender(): ?Closure
    {
        return self::$mailSender;
    }

    /**
     * @throws ConfigurationException When the package route is switched off and the application did not call linkUrlUsing().
     */
    public static function linkUrl(string $token): string
    {
        if (self::$linkUrlResolver !== null) {
            return (self::$linkUrlResolver)($token);
        }

        if (! MfaConfig::linkEnabled()) {
            throw new ConfigurationException('The MFA link route is switched off (mfa.link.enabled). Call Mfa::linkUrlUsing() to point the mailed link at a route of the application.');
        }

        return route('mfa.link', ['token' => $token]);
    }

    /**
     * The channels configured for a guard, in the order of the config.
     *
     * @return list<Channel>
     */
    public function channels(string $guard): array
    {
        $channels = [];

        foreach (MfaConfig::channels($guard) as $name) {
            if ($this->registry->has($name)) {
                $channels[] = $this->registry->get($name);
            }
        }

        return $channels;
    }

    /**
     * The channel names this user can sign in with right now: configured for
     * the guard, set up by the user and not declared down.
     *
     * @return list<string>
     */
    public function available(string $guard, Authenticatable $user): array
    {
        $names = [];

        foreach ($this->channels($guard) as $channel) {
            if (! $this->emergency->isUnavailable($channel->dependency())
                && $this->allowedFor($guard, $user, $channel->name())
                && $channel->enrolled($user)) {
                $names[] = $channel->name();
            }
        }

        return $names;
    }

    /**
     * Whether the account itself allows a channel, see RestrictsChannels.
     */
    private function allowedFor(string $guard, Authenticatable $user, string $channel): bool
    {
        return ! $user instanceof RestrictsChannels || $user->mfaAllowsChannel($channel, $guard);
    }

    /**
     * Whether a guard survives the loss of one outside service: it offers a
     * channel that needs none, or channels that lean on different ones.
     */
    public function resilient(string $guard): bool
    {
        $dependencies = array_map(fn (Channel $channel) => $channel->dependency(), $this->channels($guard));

        return in_array(null, $dependencies, true) || count(array_unique($dependencies)) > 1;
    }

    /**
     * Whether this user may sign in with only a password because an outage
     * left them without any working channel.
     */
    public function passwordOnlyAllowed(string $guard, Authenticatable $user): bool
    {
        if (! MfaConfig::emergencyAllowPasswordOnly() || ! $this->registry->has('password')) {
            return false;
        }

        $blocked = array_filter(
            $this->channels($guard),
            fn (Channel $channel) => $this->emergency->isUnavailable($channel->dependency()),
        );

        return $blocked !== [] && $this->available($guard, $user) === [];
    }

    public function findUser(string $guard, string $identifier): ?Authenticatable
    {
        $identifier = trim($identifier);

        if ($identifier === '' || ! MfaConfig::hasGuard($guard)) {
            return null;
        }

        return $this->provider($guard)->retrieveByCredentials([
            MfaConfig::identifier($guard) => $identifier,
        ]);
    }

    /**
     * Send a code (and for mail a link) to whoever the identifier belongs to.
     *
     * Returns nothing on purpose: the caller shows the same message whether
     * or not the account exists.
     *
     * @param  string|null  $channel  A sending channel; the first available one when left out.
     *
     * @throws ThrottledException
     * @throws BlockedException When the firewall refuses the address.
     * @throws ConfigurationException When the mail cannot carry a link, see linkUrl().
     */
    public function request(string $guard, string $identifier, ?string $channel = null): void
    {
        $this->ensureAllowed($guard, $identifier, LoginAttempt::METHOD_REQUEST);
        $this->throttle('request', $guard, $identifier, LoginAttempt::METHOD_REQUEST);

        $user = $this->findUser($guard, $identifier);

        if ($user === null || ! Subject::active($user)) {
            $this->record($guard, $user === null ? LoginAttempt::UNKNOWN_ACCOUNT : LoginAttempt::INACTIVE, $channel, $identifier, $user, LoginAttempt::METHOD_REQUEST);

            return;
        }

        $sender = $this->sender($guard, $user, $channel);

        if ($sender === null) {
            // An account that switched the sending channels off for itself:
            // the screen goes on as if a message went out, the log knows better.
            if ($this->restrictsEverySender($guard, $user)) {
                $this->record($guard, LoginAttempt::NOT_ALLOWED, $channel, $identifier, $user, LoginAttempt::METHOD_REQUEST);
            }

            return;
        }

        if ($sender->name() === 'sms' && $this->smsQuotaReached()) {
            return;
        }

        $issued = Challenge::issue($guard, $user, $sender->name(), $sender->withLink());

        // A link route that cannot work is the developer's to see, and now:
        // further down the message may only go out after the response.
        $issued->url();

        // Counted here and not after the delivery: by then the session may
        // already be saved.
        if ($sender->name() === 'sms') {
            $this->countSms();
        }

        $deliver = function () use ($sender, $user, $issued): void {
            try {
                $sender->send($user, $issued);
            } catch (ConfigurationException $exception) {
                // A setup that cannot work is the developer's to see, not a failed delivery.
                throw $exception;
            } catch (Throwable $exception) {
                // A failed delivery must look like any other request from the
                // outside, but the application owner has to hear about it.
                report($exception);

                return;
            }

            event(new ChallengeSent($issued->challenge, $user));
        };

        if (! MfaConfig::sendAfterResponse() || app()->runningInConsole()) {
            $deliver();

            return;
        }

        // After the response the screen has answered as fast as it does for
        // an address that does not exist, however slow the mail server is.
        app()->terminating(function () use ($deliver): void {
            try {
                $deliver();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Sign in with the code from a mail or text message.
     *
     * @param  bool|null  $remember  Keep the user signed in; null follows the guard's "remember" setting.
     *
     * @throws ThrottledException
     * @throws BlockedException When the firewall refuses the address.
     */
    public function loginWithCode(string $guard, string $identifier, string $code, ?bool $remember = null): ?Authenticatable
    {
        $this->ensureAllowed($guard, $identifier, LoginAttempt::METHOD_CODE);
        $this->throttle('verify', $guard, $identifier, LoginAttempt::METHOD_CODE);

        $user = $this->findUser($guard, $identifier);

        if ($user === null) {
            return $this->fail($guard, LoginAttempt::UNKNOWN_ACCOUNT, null, $identifier, null, LoginAttempt::METHOD_CODE);
        }

        $challenge = Challenge::findFor($guard, $user);

        if ($challenge === null || $challenge->hasExpired()) {
            return $this->fail($guard, LoginAttempt::EXPIRED, $challenge?->channel, $identifier, $user, LoginAttempt::METHOD_CODE);
        }

        if (! $this->allowedFor($guard, $user, $challenge->channel)) {
            return $this->fail($guard, LoginAttempt::NOT_ALLOWED, $challenge->channel, $identifier, $user, LoginAttempt::METHOD_CODE);
        }

        if (! $challenge->matchesCode(trim($code))) {
            return $this->fail($guard, LoginAttempt::INVALID, $challenge->channel, $identifier, $user, LoginAttempt::METHOD_CODE);
        }

        return $this->complete($guard, $user, $challenge->channel, LoginAttempt::METHOD_CODE, $identifier, false, $remember, $challenge);
    }

    /**
     * Sign in with something the user types from what they have or know: an
     * authenticator code, a recovery code, a PIN or a password.
     *
     * @throws ThrottledException
     * @throws BlockedException When the firewall refuses the address.
     */
    public function loginWithSecret(string $guard, string $identifier, string $channel, string $input, ?bool $remember = null): ?Authenticatable
    {
        $verified = $this->verifySecret($guard, $identifier, $channel, $input);

        if ($verified === null) {
            return null;
        }

        return $this->complete($guard, $verified['user'], $channel, LoginAttempt::METHOD_SECRET, $identifier, $verified['emergency'], $remember);
    }

    /**
     * Check a secret without signing in: the first step of a login that goes
     * on with a second one, such as a password followed by a mailed code.
     *
     * Throttled, judged by the firewall, logged and counted towards a block
     * exactly like loginWithSecret(); a right answer is logged as "verified".
     * What follows is up to the application, usually request() and then
     * loginWithCode().
     *
     * @throws ThrottledException
     * @throws BlockedException When the firewall refuses the address.
     */
    public function checkSecret(string $guard, string $identifier, string $channel, string $input): ?Authenticatable
    {
        $verified = $this->verifySecret($guard, $identifier, $channel, $input);

        if ($verified === null || $verified['emergency']) {
            return null;
        }

        $this->record($guard, LoginAttempt::VERIFIED, $channel, $identifier, $verified['user'], LoginAttempt::METHOD_SECRET);

        return $verified['user'];
    }

    /**
     * @return array{user: Authenticatable, emergency: bool}|null
     *
     * @throws ThrottledException
     * @throws BlockedException
     */
    private function verifySecret(string $guard, string $identifier, string $channel, string $input): ?array
    {
        $this->ensureAllowed($guard, $identifier, LoginAttempt::METHOD_SECRET);
        $this->throttle('verify', $guard, $identifier, LoginAttempt::METHOD_SECRET);

        $user = $this->findUser($guard, $identifier);

        if ($user === null) {
            return $this->fail($guard, LoginAttempt::UNKNOWN_ACCOUNT, $channel, $identifier, null, LoginAttempt::METHOD_SECRET);
        }

        if (! Subject::active($user)) {
            return $this->fail($guard, LoginAttempt::INACTIVE, $channel, $identifier, $user, LoginAttempt::METHOD_SECRET);
        }

        $emergency = false;

        if (! in_array($channel, $this->available($guard, $user), true)) {
            if ($channel !== 'password' || ! $this->passwordOnlyAllowed($guard, $user)) {
                $result = $this->registry->has($channel) && ! $this->allowedFor($guard, $user, $channel)
                    ? LoginAttempt::NOT_ALLOWED
                    : LoginAttempt::INVALID;

                return $this->fail($guard, $result, $channel, $identifier, $user, LoginAttempt::METHOD_SECRET);
            }

            $emergency = true;
        }

        $verifier = $this->registry->get($channel);

        if (! $verifier instanceof VerifiesSecret || ! $verifier->enrolled($user) || ! $verifier->verify($user, $input)) {
            return $this->fail($guard, LoginAttempt::INVALID, $channel, $identifier, $user, LoginAttempt::METHOD_SECRET);
        }

        return ['user' => $user, 'emergency' => $emergency];
    }

    /**
     * Sign in with the token from a mailed link. A link that was used or has
     * expired is logged for its owner; a token that never existed is not.
     *
     * @throws BlockedException When the firewall refuses the address.
     */
    public function loginWithLink(string $token, ?bool $remember = null): ?Authenticatable
    {
        $challenge = Challenge::findByToken($token);

        if ($challenge === null || ! MfaConfig::hasGuard($challenge->guard)) {
            return null;
        }

        $guard = $challenge->guard;
        $user = $this->provider($guard)->retrieveById($challenge->authenticatable_id);

        if ($user === null || Subject::type($user) !== $challenge->authenticatable_type) {
            return null;
        }

        // After the owner is known, so a refusal is logged for them.
        $this->ensureAllowed($guard, null, LoginAttempt::METHOD_LINK, $user);

        if ($challenge->hasExpired()) {
            return $this->fail($guard, LoginAttempt::EXPIRED, $challenge->channel, null, $user, LoginAttempt::METHOD_LINK);
        }

        if (! $this->allowedFor($guard, $user, $challenge->channel)) {
            return $this->fail($guard, LoginAttempt::NOT_ALLOWED, $challenge->channel, null, $user, LoginAttempt::METHOD_LINK);
        }

        return $this->complete($guard, $user, $challenge->channel, LoginAttempt::METHOD_LINK, null, false, $remember, $challenge);
    }

    /**
     * Whether a link token still points at a usable challenge. Does not use
     * the token up.
     */
    public function linkIsUsable(string $token): bool
    {
        $challenge = Challenge::findByToken($token);

        return $challenge !== null && ! $challenge->hasExpired();
    }

    /**
     * Write a line to the login log and tell the application.
     */
    public function record(string $guard, string $result, ?string $channel = null, ?string $identifier = null, ?Authenticatable $user = null, ?string $method = null): void
    {
        if (MfaConfig::logEnabled()) {
            try {
                LoginAttempt::query()->create([
                    'guard' => $guard,
                    'authenticatable_type' => $user === null ? null : Subject::type($user),
                    'authenticatable_id' => $user === null ? null : Subject::id($user),
                    'identifier' => $identifier === null ? null : Str::limit($identifier, 250, ''),
                    'channel' => $channel,
                    'method' => $method,
                    'result' => $result,
                    'ip' => request()->ip(),
                    'country' => $this->firewall->country(request()->ip()),
                    'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
                ]);
            } catch (Throwable $exception) {
                // The log never decides whether somebody gets in.
                report($exception);
            }
        }

        event(new LoginAttempted($guard, $result, $channel, $identifier, $user, $method));
    }

    /**
     * @param  Challenge|null  $used  The challenge the user proved, kept as consumed; null for a typed secret.
     */
    private function complete(string $guard, Authenticatable $user, string $channel, string $method, ?string $identifier = null, bool $emergency = false, ?bool $remember = null, ?Challenge $used = null): ?Authenticatable
    {
        if (! Subject::active($user)) {
            return $this->fail($guard, LoginAttempt::INACTIVE, $channel, $identifier, $user, $method);
        }

        if ($used !== null && ! $used->consume()) {
            // A second request with the same code or link lost the race.
            return $this->fail($guard, LoginAttempt::EXPIRED, $channel, $identifier, $user, $method);
        }

        // Every other outstanding challenge dies with the one that was used.
        Challenge::owned($guard, $user)
            ->when($used !== null, fn ($query) => $query->whereKeyNot($used?->getKey()))
            ->delete();

        $this->guard($guard)->login($user, $remember ?? MfaConfig::remember($guard));

        $this->firewall->clearFailures(request()->ip());

        if (request()->hasSession()) {
            $session = request()->session();
            $session->regenerate();

            // There may be no password at all, and sensitive actions check this.
            $session->put('auth.password_confirmed_at', time());

            if ($emergency) {
                $session->put(self::SESSION_EMERGENCY.$guard, time());
            } else {
                $session->forget(self::SESSION_EMERGENCY.$guard);
            }
        }

        if ($identifier !== null) {
            RateLimiter::clear($this->throttleKey('request', $guard, $identifier));
            RateLimiter::clear($this->throttleKey('verify', $guard, $identifier));
            RateLimiter::clear($this->accountKey($guard, $identifier));
        }

        $this->record($guard, $emergency ? LoginAttempt::EMERGENCY : LoginAttempt::SUCCEEDED, $channel, $identifier, $user, $method);

        return $user;
    }

    private function fail(string $guard, string $result, ?string $channel, ?string $identifier, ?Authenticatable $user = null, ?string $method = null): null
    {
        $this->record($guard, $result, $channel, $identifier, $user, $method);

        // Wrong guesses count towards a block of the address; asking for a
        // mail and a stale link do not.
        if (in_array($result, [LoginAttempt::INVALID, LoginAttempt::UNKNOWN_ACCOUNT], true) && $method !== LoginAttempt::METHOD_REQUEST) {
            $this->firewall->registerFailure(request()->ip());
        }

        return null;
    }

    /**
     * Stop here when the firewall refuses the address of the request.
     *
     * @throws BlockedException
     */
    private function ensureAllowed(string $guard, ?string $identifier, string $method, ?Authenticatable $user = null): void
    {
        $verdict = $this->firewall->decide(request()->ip(), $guard);

        if ($verdict->allowed) {
            return;
        }

        $this->record($guard, (string) $verdict->reason, null, $identifier, $user, $method);

        throw new BlockedException((string) $verdict->reason, $verdict->until);
    }

    /**
     * Whether the guard has sending channels and the account allows none.
     */
    private function restrictsEverySender(string $guard, Authenticatable $user): bool
    {
        $senders = array_filter($this->channels($guard), fn (Channel $channel) => $channel instanceof SendsChallenge);

        return $senders !== []
            && array_filter($senders, fn (Channel $channel) => $this->allowedFor($guard, $user, $channel->name())) === [];
    }

    private function sender(string $guard, Authenticatable $user, ?string $channel): ?SendsChallenge
    {
        foreach ($this->available($guard, $user) as $name) {
            $candidate = $this->registry->get($name);

            if ($candidate instanceof SendsChallenge && ($channel === null || $channel === $name)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The user provider behind a guard.
     */
    public function provider(string $guard): UserProvider
    {
        return $this->guard($guard)->getProvider();
    }

    private function guard(string $guard): SessionGuard
    {
        $instance = $this->auth->guard($guard);

        if (! $instance instanceof SessionGuard) {
            throw new InvalidArgumentException("MFA guard [{$guard}] must be a session guard.");
        }

        return $instance;
    }

    /**
     * @throws ThrottledException
     */
    private function throttle(string $action, string $guard, string $identifier, string $method): void
    {
        $key = $this->throttleKey($action, $guard, $identifier);
        $account = $this->accountKey($guard, $identifier);

        // Guessing at one account is also counted over every address
        // together. Asking for a mail is not: whoever is under attack must
        // still be able to get a link.
        $limits = [[$key, MfaConfig::rateLimitAttempts(), MfaConfig::rateLimitDecaySeconds()]];

        if ($action === 'verify' && MfaConfig::rateLimitAccountAttempts() > 0) {
            $limits[] = [$account, MfaConfig::rateLimitAccountAttempts(), MfaConfig::rateLimitAccountDecaySeconds()];
        }

        foreach ($limits as [$limit, $attempts]) {
            if (RateLimiter::tooManyAttempts($limit, $attempts)) {
                $this->record($guard, LoginAttempt::THROTTLED, null, $identifier, $this->findUser($guard, $identifier), $method);

                throw new ThrottledException(RateLimiter::availableIn($limit));
            }
        }

        foreach ($limits as [$limit, , $decay]) {
            RateLimiter::hit($limit, $decay);
        }
    }

    private function throttleKey(string $action, string $guard, string $identifier): string
    {
        return $this->accountKey($guard, $identifier, $action).'|'.Firewall::network((string) request()->ip());
    }

    private function accountKey(string $guard, string $identifier, string $action = 'account'): string
    {
        return Str::transliterate('mfa|'.$action.'|'.$guard.'|'.Str::lower(trim($identifier)));
    }

    /**
     * Every text message costs money, so one session can only send so many.
     */
    private function smsQuotaReached(): bool
    {
        return request()->hasSession()
            && (int) request()->session()->get(self::SESSION_SMS_COUNT, 0) >= MfaConfig::smsMaxPerSession();
    }

    private function countSms(): void
    {
        if (request()->hasSession()) {
            request()->session()->increment(self::SESSION_SMS_COUNT);
        }
    }
}
