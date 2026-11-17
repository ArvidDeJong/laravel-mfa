<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Livewire\Concerns\AuthorizesManagement;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Models\FirewallRule;
use Darvis\Mfa\Support\MfaConfig;
use Darvis\Mfa\Support\Overview as OverviewData;
use Darvis\Mfa\Support\Subject;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signing in at a glance: how every guard is set up, whether the firewall is
 * on and which countries it lets in, who is blocked right now, and which
 * ways in each user has set up.
 */
class Overview extends Component
{
    use AuthorizesManagement, RendersInLayout, WithPagination;

    public string $usersGuard = '';

    public string $search = '';

    public function mount(): void
    {
        $this->authorizeManagement();

        $this->usersGuard = MfaConfig::guards()[0] ?? '';
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function lift(int $id, Firewall $firewall): void
    {
        $this->authorizeLifting();

        $block = FirewallRule::query()->active()->where('automatic', true)->find($id);

        if ($block instanceof FirewallRule) {
            $firewall->lift($block, Subject::actor());
        }
    }

    public function render(OverviewData $overview): View
    {
        $this->authorizeManagement();

        $guard = MfaConfig::hasGuard($this->usersGuard) ? $this->usersGuard : (MfaConfig::guards()[0] ?? '');

        return $this->screen('mfa::livewire.overview', [
            'blocks' => $overview->blocks(),
            'firewall' => $overview->firewall(),
            'guard' => $guard,
            'guards' => $overview->guards(),
            'overview' => $overview,
            'pastBlocks' => $overview->pastBlocks(10),
            'users' => $guard === '' ? null : $overview->users($guard, $this->search),
        ], __('mfa::mfa.overview_title'));
    }
}
