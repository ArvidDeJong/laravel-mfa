<?php

namespace Darvis\Mfa\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * The firewall and login log are for whoever the application's "manageMfa"
 * gate lets in. Without that gate they only open in the local environment,
 * like Horizon.
 */
trait AuthorizesManagement
{
    protected function authorizeManagement(): void
    {
        $allowed = Gate::has('manageMfa')
            ? Gate::allows('manageMfa')
            : app()->environment('local');

        abort_unless($allowed, 403);
    }
}
