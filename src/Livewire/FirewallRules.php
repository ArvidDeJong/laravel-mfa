<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Livewire\Concerns\AuthorizesManagement;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Subject;
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
        $this->authorizeLifting();
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
            'created_by' => Subject::actor(),
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
     * Remove a rule, or lift an automatic block.
     */
    public function remove(int $id, Firewall $firewall): void
    {
        $this->resetErrorBag();

        $rule = FirewallRule::query()->find($id);

        // A block is lifted, so the row keeps who did; a rule somebody added
        // by hand is simply removed, and only by who manages the firewall.
        if ($rule instanceof FirewallRule && $rule->automatic) {
            $this->authorizeLifting();

            $firewall->lift($rule, Subject::actor());

            return;
        }

        $this->authorizeManagement();

        $rule?->delete();
        $firewall->refresh();
    }

    public function render(): View
    {
        $this->authorizeLifting();

        $canManage = $this->canManage();

        return $this->screen('mfa::livewire.firewall', [
            'canManage' => $canManage,
            'enabled' => MfaConfig::firewallEnabled(),
            'fixed' => $canManage ? [
                'allow' => MfaConfig::firewallList('allow'),
                'deny' => MfaConfig::firewallList('deny'),
                'locations' => MfaConfig::firewallList('locations'),
            ] : [],
            'guards' => MfaConfig::guards(),
            // Who may only lift blocks sees the blocks and nothing else.
            'rules' => FirewallRule::query()->active()->when(! $canManage, fn ($query) => $query->where('automatic', true))->orderByDesc('automatic')->latest('id')->limit(200)->get(),
            'yourIp' => request()->ip(),
        ], __('mfa::mfa.firewall_title'));
    }
}
