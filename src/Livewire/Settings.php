<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Enrollment;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Models\Factor;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\QrCode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Where a signed-in user sets up the ways in that need setting up: an
 * authenticator app, a phone number, recovery codes and a PIN. Only the
 * channels the guard offers are shown.
 *
 *     Route::get('admin/security', Settings::class)->defaults('guard', 'staff')->middleware('auth:staff');
 */
class Settings extends Component
{
    use RendersInLayout;

    #[Locked]
    public string $guard = '';

    /**
     * The otpauth:// URI and secret of an authenticator that is being set up.
     */
    #[Locked]
    public ?string $authenticatorUri = null;

    #[Locked]
    public ?string $authenticatorSecret = null;

    public string $authenticatorCode = '';

    /**
     * Fresh recovery codes, shown once.
     *
     * @var list<string>
     */
    #[Locked]
    public array $recoveryCodes = [];

    public string $phone = '';

    public string $smsCode = '';

    #[Locked]
    public bool $smsPending = false;

    public string $pin = '';

    public function mount(?string $guard = null): void
    {
        $this->guard = $guard ?? (MfaConfig::guards()[0] ?? '');

        abort_unless(MfaConfig::hasGuard($this->guard), 404);

        $this->user();
    }

    public function startAuthenticator(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        $factor = $enrollment->startAuthenticator($this->user());

        $this->authenticatorUri = $enrollment->authenticatorUri($this->user(), $factor);
        $this->authenticatorSecret = $factor->secret;
        $this->authenticatorCode = '';
    }

    public function confirmAuthenticator(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        if (! $enrollment->confirmAuthenticator($this->user(), $this->authenticatorCode)) {
            $this->addError('authenticatorCode', __('mfa::mfa.settings_code_wrong'));

            return;
        }

        $this->reset('authenticatorUri', 'authenticatorSecret', 'authenticatorCode');
    }

    public function generateRecoveryCodes(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        $this->recoveryCodes = $enrollment->generateRecoveryCodes($this->user());
    }

    public function startSms(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        try {
            $enrollment->startSms($this->guard, $this->user(), $this->phone);
        } catch (InvalidArgumentException) {
            $this->addError('phone', __('mfa::mfa.settings_phone_invalid'));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('phone', __('mfa::mfa.settings_sms_failed'));

            return;
        }

        $this->smsPending = true;
        $this->smsCode = '';
    }

    public function confirmSms(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        if (! $enrollment->confirmSms($this->guard, $this->user(), $this->smsCode)) {
            $this->addError('smsCode', __('mfa::mfa.settings_code_wrong'));

            return;
        }

        $this->reset('phone', 'smsCode', 'smsPending');
    }

    public function savePin(Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        try {
            $enrollment->setPin($this->user(), $this->pin);
        } catch (InvalidArgumentException) {
            $this->addError('pin', __('mfa::mfa.settings_pin_invalid', ['length' => MfaConfig::pinMinLength()]));

            return;
        }

        $this->reset('pin');
    }

    /**
     * Remove a way in: authenticator, sms, recovery or pin.
     */
    public function remove(string $type, Enrollment $enrollment): void
    {
        $this->resetErrorBag();

        if (in_array($type, [Factor::AUTHENTICATOR, Factor::SMS, Factor::RECOVERY, Factor::PIN], true)) {
            $enrollment->remove($this->user(), $type);
        }

        if ($type === Factor::RECOVERY) {
            $this->recoveryCodes = [];
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $has = fn (string $type): bool => Factor::confirmed($user, $type)->exists();

        return $this->screen('mfa::livewire.settings', [
            'channels' => MfaConfig::channels($this->guard),
            'hasAuthenticator' => $has(Factor::AUTHENTICATOR),
            'hasPin' => $has(Factor::PIN),
            'hasSms' => $has(Factor::SMS),
            'qr' => $this->authenticatorUri === null ? null : QrCode::svg($this->authenticatorUri),
            'recoveryLeft' => Factor::confirmed($user, Factor::RECOVERY)->count(),
        ], __('mfa::mfa.settings_title'), $this->guard);
    }

    private function user(): Authenticatable
    {
        $user = Auth::guard($this->guard)->user();

        abort_if($user === null, 403);

        return $user;
    }
}
