<?php

namespace Darvis\Mfa\Livewire\Concerns;

use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Contracts\View\View;

/**
 * Renders a screen in the layout config/mfa.php names for its guard.
 */
trait RendersInLayout
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function screen(string $view, array $data, string $title, ?string $guard = null): View
    {
        $screen = view($view, $data)->title($title);
        $layout = MfaConfig::layout($guard);

        return $layout === null ? $screen : $screen->layout($layout);
    }
}
