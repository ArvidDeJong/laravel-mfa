<?php

namespace Darvis\Mfa;

use Darvis\Mfa\Contracts\Channel;
use Darvis\Mfa\Contracts\SendsChallenge;
use Darvis\Mfa\Contracts\VerifiesSecret;
use Darvis\Mfa\Events\ChallengeSent;
use Darvis\Mfa\Events\LoginAttempted;
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Models\Challenge;
use Darvis\Mfa\Models\LoginAttempt;
use Darvis\Mfa\Support\ChannelRegistry;
use Darvis\Mfa\Support\Emergency;
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

    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly Emergency $emergency,
        private readonly AuthFactory $auth,
    ) {}

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
            if (! $this->emergency->isUnavailable($channel->dependency()) && $channel->enrolled($user)) {
                $names[] = $channel->name();
            }
        }

        return $names;
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
     */
    public function request(string $guard, string $identifier, ?string $channel = null): void
    {
        $this->throttle('request', $guard, $identifier);

        $user = $this->findUser($guard, $identifier);

        if ($user === null || ! Subject::active($user)) {
            $this->record($guard, $user === null ? LoginAttempt::UNKNOWN_ACCOUNT : LoginAttempt::INACTIVE, $channel, $identifier, $user);

            return;
        }

        $sender = $this->sender($guard, $user, $channel);

        if ($sender === null || ($sender->name() === 'sms' && $this->smsQuotaReached())) {
            return;
        }

        $issued = Challenge::issue($guard, $user, $sender->name(), $sender->withLink());

        try {
            $sender->send($user, $issued);
        } catch (Throwable $exception) {
            // A failed delivery must look like any other request from the
            // outside, but the application owner has to hear about it.
            report($exception);

            return;
        }

        if ($sender->name() === 'sms') {
            $this->countSms();
        }

        event(new ChallengeSent($issued->challenge, $user));
    }

    /**
     * Sign in with the code from a mail or text message.
     *
     * @throws ThrottledException
     */
    public function loginWithCode(string $guard, string $identifier, string $code): ?Authenticatable
    {
        $this->throttle('verify', $guard, $identifier);

        $user = $this->findUser($guard, $identifier);

        if ($user === null) {
            return $this->fail($guard, LoginAttempt::UNKNOWN_ACCOUNT, null, $identifier);
        }

        $challenge = Challenge::findFor($guard, $user);

        if ($challenge === null || $challenge->hasExpired()) {
            return $this->fail($guard, LoginAttempt::EXPIRED, $challenge?->channel, $identifier, $user);
        }

        if (! $challenge->matchesCode(trim($code))) {
            return $this->fail($guard, LoginAttempt::INVALID, $challenge->channel, $identifier, $user);
        }

        return $this->complete($guard, $user, $challenge->channel, $identifier);
    }

    /**
     * Sign in with something the user types from what they have or know: an
     * authenticator code, a recovery code or a password.
     *
     * @throws ThrottledException
     */
    public function loginWithSecret(string $guard, string $identifier, string $channel, string $input): ?Authenticatable
    {
        $this->throttle('verify', $guard, $identifier);

        $user = $this->findUser($guard, $identifier);

        if ($user === null) {
            return $this->fail($guard, LoginAttempt::UNKNOWN_ACCOUNT, $channel, $identifier);
        }

        $emergency = false;

        if (! in_array($channel, $this->available($guard, $user), true)) {
            if ($channel !== 'password' || ! $this->passwordOnlyAllowed($guard, $user)) {
                return $this->fail($guard, LoginAttempt::INVALID, $channel, $identifier, $user);
            }

            $emergency = true;
        }

        $verifier = $this->registry->get($channel);

        if (! $verifier instanceof VerifiesSecret || ! $verifier->enrolled($user) || ! $verifier->verify($user, $input)) {
            return $this->fail($guard, LoginAttempt::INVALID, $channel, $identifier, $user);
        }

        return $this->complete($guard, $user, $channel, $identifier, $emergency);
    }

    /**
     * Sign in with the token from a mailed link.
     */
    public function loginWithLink(string $token): ?Authenticatable
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

        if ($challenge->hasExpired()) {
            return $this->fail($guard, LoginAttempt::EXPIRED, $challenge->channel, null, $user);
        }

        return $this->complete($guard, $user, $challenge->channel);
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
    public function record(string $guard, string $result, ?string $channel = null, ?string $identifier = null, ?Authenticatable $user = null): void
    {
        if (MfaConfig::logEnabled()) {
            try {
                LoginAttempt::query()->create([
                    'guard' => $guard,
                    'authenticatable_type' => $user === null ? null : Subject::type($user),
                    'authenticatable_id' => $user === null ? null : Subject::id($user),
                    'identifier' => $identifier === null ? null : Str::limit($identifier, 250, ''),
                    'channel' => $channel,
                    'result' => $result,
                    'ip' => request()->ip(),
                    'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
                ]);
            } catch (Throwable $exception) {
                // The log never decides whether somebody gets in.
                report($exception);
            }
        }

        event(new LoginAttempted($guard, $result, $channel, $identifier, $user));
    }

    private function complete(string $guard, Authenticatable $user, string $channel, ?string $identifier = null, bool $emergency = false): ?Authenticatable
    {
        if (! Subject::active($user)) {
            return $this->fail($guard, LoginAttempt::INACTIVE, $channel, $identifier, $user);
        }

        // Every outstanding challenge dies with the one that was used.
        Challenge::owned($guard, $user)->delete();

        $this->guard($guard)->login($user, MfaConfig::remember($guard));

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
        }

        $this->record($guard, $emergency ? LoginAttempt::EMERGENCY : LoginAttempt::SUCCEEDED, $channel, $identifier, $user);

        return $user;
    }

    private function fail(string $guard, string $result, ?string $channel, ?string $identifier, ?Authenticatable $user = null): null
    {
        $this->record($guard, $result, $channel, $identifier, $user);

        return null;
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
    private function throttle(string $action, string $guard, string $identifier): void
    {
        $key = $this->throttleKey($action, $guard, $identifier);

        if (RateLimiter::tooManyAttempts($key, MfaConfig::rateLimitAttempts())) {
            $this->record($guard, LoginAttempt::THROTTLED, null, $identifier);

            throw new ThrottledException(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, MfaConfig::rateLimitDecaySeconds());
    }

    private function throttleKey(string $action, string $guard, string $identifier): string
    {
        return Str::transliterate('mfa|'.$action.'|'.$guard.'|'.Str::lower(trim($identifier)).'|'.request()->ip());
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
