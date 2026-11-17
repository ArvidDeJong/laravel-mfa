<?php

namespace Darvis\Mfa\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * The firewall and login log are for whoever the application's "manageMfa"
 * gate lets in. Without that gate they only open in the local environment,
 * like Horizon.
 *
 * An optional second gate, "liftMfaBlocks", lets a wider group lift a block
 * that followed failed logins without managing the rules: a colleague who
 * recognises the address of the office.
 */
trait AuthorizesManagement
{
    protected function authorizeManagement(): void
    {
        abort_unless($this->canManage(), 403);
    }

    protected function authorizeLifting(): void
    {
        abort_unless($this->canLift(), 403);
    }

    protected function canManage(): bool
    {
        return Gate::has('manageMfa')
            ? Gate::allows('manageMfa')
            : app()->environment('local');
    }

    protected function canLift(): bool
    {
        return $this->canManage() || (Gate::has('liftMfaBlocks') && Gate::allows('liftMfaBlocks'));
    }
}
