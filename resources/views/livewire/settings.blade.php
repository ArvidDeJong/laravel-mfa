<div class="flex w-full max-w-2xl flex-col gap-8">
    <div class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('mfa::mfa.settings_title') }}</flux:heading>
        <flux:text>{{ __('mfa::mfa.settings_intro') }}</flux:text>
    </div>

    @if (in_array('authenticator', $channels, true))
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <flux:heading>{{ __('mfa::mfa.channel_authenticator') }}</flux:heading>
                <flux:badge :color="$hasAuthenticator ? 'green' : 'zinc'">{{ $hasAuthenticator ? __('mfa::mfa.settings_on') : __('mfa::mfa.settings_off') }}</flux:badge>
            </div>

            @if ($authenticatorUri !== null)
                <flux:text>{{ __('mfa::mfa.settings_authenticator_scan') }}</flux:text>

                @if ($qr !== null)
                    <div class="w-48 rounded bg-white p-2">{!! $qr !!}</div>
                @endif

                <flux:text size="sm">{{ __('mfa::mfa.settings_authenticator_key') }} <code class="font-mono break-all">{{ $authenticatorSecret }}</code></flux:text>

                <form wire:submit="confirmAuthenticator" class="flex items-end gap-3">
                    <flux:input wire:model="authenticatorCode" :label="__('mfa::mfa.login_code')" inputmode="numeric" autocomplete="one-time-code" />
                    <flux:button type="submit" variant="primary">{{ __('mfa::mfa.settings_confirm') }}</flux:button>
                </form>
            @else
                <div class="flex gap-3">
                    <flux:button wire:click="startAuthenticator">{{ $hasAuthenticator ? __('mfa::mfa.settings_replace') : __('mfa::mfa.settings_set_up') }}</flux:button>

                    @if ($hasAuthenticator)
                        <flux:button variant="ghost" wire:click="remove('authenticator')" wire:confirm="{{ __('mfa::mfa.settings_remove_confirm') }}">{{ __('mfa::mfa.settings_remove') }}</flux:button>
                    @endif
                </div>
            @endif
        </flux:card>
    @endif

    @if (in_array('recovery', $channels, true))
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <flux:heading>{{ __('mfa::mfa.channel_recovery') }}</flux:heading>
                <flux:badge :color="$recoveryLeft > 0 ? 'green' : 'zinc'">{{ trans_choice('mfa::mfa.settings_recovery_left', $recoveryLeft, ['count' => $recoveryLeft]) }}</flux:badge>
            </div>

            @if ($recoveryCodes !== [])
                <flux:text>{{ __('mfa::mfa.settings_recovery_once') }}</flux:text>

                <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                    @foreach ($recoveryCodes as $code)
                        <div wire:key="recovery-{{ $loop->index }}">{{ $code }}</div>
                    @endforeach
                </div>
            @endif

            <div>
                <flux:button wire:click="generateRecoveryCodes" wire:confirm="{{ __('mfa::mfa.settings_recovery_confirm') }}">{{ __('mfa::mfa.settings_recovery_new') }}</flux:button>
            </div>
        </flux:card>
    @endif

    @if (in_array('sms', $channels, true))
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <flux:heading>{{ __('mfa::mfa.channel_sms') }}</flux:heading>
                <flux:badge :color="$hasSms ? 'green' : 'zinc'">{{ $hasSms ? __('mfa::mfa.settings_on') : __('mfa::mfa.settings_off') }}</flux:badge>
            </div>

            @if ($smsPending)
                <form wire:submit="confirmSms" class="flex items-end gap-3">
                    <flux:input wire:model="smsCode" :label="__('mfa::mfa.login_code')" inputmode="numeric" autocomplete="one-time-code" />
                    <flux:button type="submit" variant="primary">{{ __('mfa::mfa.settings_confirm') }}</flux:button>
                </form>
            @else
                <form wire:submit="startSms" class="flex items-end gap-3">
                    <flux:input wire:model="phone" :label="__('mfa::mfa.settings_phone')" type="tel" placeholder="+31612345678" />
                    <flux:button type="submit">{{ __('mfa::mfa.settings_send_code') }}</flux:button>
                </form>

                @if ($hasSms)
                    <div>
                        <flux:button variant="ghost" wire:click="remove('sms')" wire:confirm="{{ __('mfa::mfa.settings_remove_confirm') }}">{{ __('mfa::mfa.settings_remove') }}</flux:button>
                    </div>
                @endif
            @endif
        </flux:card>
    @endif

    @if (in_array('pin', $channels, true))
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <flux:heading>{{ __('mfa::mfa.channel_pin') }}</flux:heading>
                <flux:badge :color="$hasPin ? 'green' : 'zinc'">{{ $hasPin ? __('mfa::mfa.settings_on') : __('mfa::mfa.settings_off') }}</flux:badge>
            </div>

            <flux:text>{{ __('mfa::mfa.settings_pin_intro') }}</flux:text>

            <form wire:submit="savePin" class="flex items-end gap-3">
                <flux:input wire:model="pin" :label="__('mfa::mfa.settings_pin_new')" type="password" inputmode="numeric" autocomplete="off" />
                <flux:button type="submit">{{ __('mfa::mfa.settings_save') }}</flux:button>
            </form>

            @if ($hasPin)
                <div>
                    <flux:button variant="ghost" wire:click="remove('pin')" wire:confirm="{{ __('mfa::mfa.settings_remove_confirm') }}">{{ __('mfa::mfa.settings_remove') }}</flux:button>
                </div>
            @endif
        </flux:card>
    @endif
</div>
