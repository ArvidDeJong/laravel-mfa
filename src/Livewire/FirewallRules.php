<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Livewire\Concerns\AuthorizesManagement;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Manage the firewall: let an address in or keep it out, for ever or until a
 * date, mark a location, and lift a block that followed failed logins.
 */
class FirewallRules extends Component
{
    use AuthorizesManagement, RendersInLayout;

    public string $action = FirewallRule::ALLOW;

    public string $range = '';

    public string $note = '';

    public string $until = '';

    public string $ruleGuard = '';

    public bool $isLocation = false;

    public bool $allowsPin = false;

    public bool $neverBlock = false;

    public function mount(): void
    {
        $this->authorizeManagement();
    }

    public function add(Firewall $firewall): void
    {
        $this->resetErrorBag();

        $this->authorizeManagement();

        if (! FirewallRule::isValidRange(trim($this->range))) {
            $this->addError('range', __('mfa::mfa.firewall_range_invalid'));

            return;
        }

        $expiresAt = null;

        if (trim($this->until) !== '') {
            try {
                $expiresAt = Carbon::parse($this->until)->endOfDay();
            } catch (Throwable) {
                $expiresAt = null;
            }

            if ($expiresAt === null || $expiresAt->isPast()) {
                $this->addError('until', __('mfa::mfa.firewall_until_invalid'));

                return;
            }
        }

        $allow = $this->action !== FirewallRule::DENY;

        FirewallRule::query()->create([
            'action' => $allow ? FirewallRule::ALLOW : FirewallRule::DENY,
            'allows_pin' => $allow && $this->allowsPin,
            'expires_at' => $expiresAt,
            'guard' => MfaConfig::hasGuard($this->ruleGuard) ? $this->ruleGuard : null,
            'is_location' => $allow && ($this->isLocation || $this->allowsPin),
            'never_block' => $allow && $this->neverBlock,
            'note' => trim($this->note) === '' ? null : mb_substr(trim($this->note), 0, 250),
            'range' => trim($this->range),
        ]);

        $firewall->refresh();

        $this->reset('range', 'note', 'until', 'ruleGuard', 'isLocation', 'allowsPin', 'neverBlock');
    }

    /**
     * Remove a rule; for an automatic block this lifts the block.
     */
    public function remove(int $id, Firewall $firewall): void
    {
        $this->resetErrorBag();

        $this->authorizeManagement();

        FirewallRule::query()->whereKey($id)->delete();

        $firewall->refresh();
    }

    public function render(): View
    {
        return $this->screen('mfa::livewire.firewall', [
            'enabled' => MfaConfig::firewallEnabled(),
            'fixed' => [
                'allow' => MfaConfig::firewallList('allow'),
                'deny' => MfaConfig::firewallList('deny'),
                'locations' => MfaConfig::firewallList('locations'),
            ],
            'guards' => MfaConfig::guards(),
            'rules' => FirewallRule::query()->active()->orderByDesc('automatic')->latest('id')->limit(200)->get(),
            'yourIp' => request()->ip(),
        ], __('mfa::mfa.firewall_title'));
    }
}
