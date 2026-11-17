<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Contracts\SendsChallenge;
use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Support\ChannelRegistry;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The login screen of one guard: type who you are, pick a way in, prove it.
 *
 * Every channel the guard offers is shown to every visitor, whether or not
 * the address typed has it set up, so the screen does not reveal who uses
 * what. Only "pin" depends on where the visitor is: it is offered on a
 * location and nowhere else.
 *
 * Route it with the guard as a route default:
 *
 *     Route::get('admin/login', Login::class)->defaults('guard', 'staff');
 */
class Login extends Component
{
    use RendersInLayout;

    #[Locked]
    public string $guard = '';

    public string $identifier = '';

    public string $channel = '';

    public string $secret = '';

    public bool $remember = false;

    /**
     * Whether a code went out and the screen is waiting for it.
     */
    public bool $sent = false;

    public function mount(?string $guard = null): void
    {
        $this->guard = $guard ?? (MfaConfig::guards()[0] ?? '');

        abort_unless(MfaConfig::hasGuard($this->guard), 404);

        $this->channel = $this->channels()[0] ?? '';
        $this->remember = MfaConfig::remember($this->guard);
    }

    /**
     * Send a code for a channel that mails or texts one.
     */
    public function send(Mfa $mfa): void
    {
        $this->resetErrorBag();

        $this->validate(['identifier' => ['required', 'string', 'max:255']]);

        if (! $this->sends($this->channel)) {
            return;
        }

        try {
            $mfa->request($this->guard, $this->identifier, $this->channel);
        } catch (ThrottledException $exception) {
            $this->addError('identifier', __('mfa::mfa.throttled', ['seconds' => $exception->seconds]));

            return;
        } catch (BlockedException $exception) {
            $this->addError('identifier', $exception->getMessage());

            return;
        }

        // The same turn of the screen for every address.
        $this->reset('secret');
        $this->sent = true;
    }

    /**
     * Sign in with what was typed: the code that went out, or an
     * authenticator code, recovery code, PIN or password.
     */
    public function signIn(Mfa $mfa): void
    {
        $this->resetErrorBag();

        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'max:255'],
        ]);

        if (! in_array($this->channel, $this->channels(), true)) {
            return;
        }

        try {
            $user = $this->sends($this->channel)
                ? $mfa->loginWithCode($this->guard, $this->identifier, $this->secret, $this->remember)
                : $mfa->loginWithSecret($this->guard, $this->identifier, $this->channel, $this->secret, $this->remember);
        } catch (ThrottledException $exception) {
            $this->addError('secret', __('mfa::mfa.throttled', ['seconds' => $exception->seconds]));

            return;
        } catch (BlockedException $exception) {
            $this->addError('secret', $exception->getMessage());

            return;
        }

        if ($user === null) {
            // One message for every way this can fail.
            $this->addError('secret', __('mfa::mfa.login_failed'));

            return;
        }

        $this->redirectIntended(MfaConfig::home($this->guard));
    }

    /**
     * Switch to another way in.
     */
    public function choose(string $channel): void
    {
        if (! in_array($channel, $this->channels(), true)) {
            return;
        }

        $this->channel = $channel;
        $this->reset('secret', 'sent');
        $this->resetValidation();
    }

    /**
     * Start over with another address.
     */
    public function startOver(): void
    {
        $this->reset('secret', 'sent');
        $this->resetValidation();
    }

    public function render(): View
    {
        return $this->screen('mfa::livewire.login', [
            'channels' => $this->channels(),
            'sends' => $this->sends($this->channel),
        ], __('mfa::mfa.login_title'), $this->guard);
    }

    /**
     * The channels this screen offers: those of the guard, with "pin" only on
     * a location that allows it.
     *
     * @return list<string>
     */
    private function channels(): array
    {
        $registry = app(ChannelRegistry::class);
        $pinHere = app(Firewall::class)->pinAllowed(request()->ip());

        return array_values(array_filter(
            MfaConfig::channels($this->guard),
            fn (string $name) => $registry->has($name) && ($name !== 'pin' || $pinHere),
        ));
    }

    private function sends(string $channel): bool
    {
        $registry = app(ChannelRegistry::class);

        return $registry->has($channel) && $registry->get($channel) instanceof SendsChallenge;
    }
}
