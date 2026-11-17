<?php

namespace Darvis\Mfa\Livewire;

use Darvis\Mfa\Livewire\Concerns\AuthorizesManagement;
use Darvis\Mfa\Livewire\Concerns\RendersInLayout;
use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The login log: who tried to sign in, how, from where, and how it ended.
 */
class LoginAttempts extends Component
{
    use AuthorizesManagement, RendersInLayout, WithPagination;

    public string $search = '';

    public bool $failedOnly = false;

    public function mount(): void
    {
        $this->authorizeManagement();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeManagement();

        $search = trim($this->search);

        return $this->screen('mfa::livewire.login-attempts', [
            'attempts' => LoginAttempt::query()
                ->when($search !== '', fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query->where('identifier', 'like', "%{$search}%")->orWhere('ip', 'like', "%{$search}%")
                ))
                ->when($this->failedOnly, fn (Builder $query) => $query->whereNotIn('result', [LoginAttempt::SUCCEEDED, LoginAttempt::EMERGENCY, LoginAttempt::EMERGENCY_LINK_ISSUED]))
                ->latest('id')
                ->paginate(25),
        ], __('mfa::mfa.attempts_title'));
    }
}
