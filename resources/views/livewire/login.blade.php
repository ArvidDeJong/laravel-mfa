<div class="mx-auto flex w-full max-w-sm flex-col gap-6">
    <div class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('mfa::mfa.login_title') }}</flux:heading>
        <flux:text>{{ __('mfa::mfa.login_channel_'.($channel ?: 'none')) }}</flux:text>
    </div>

    @if ($sends && $sent)
        <form wire:submit="signIn" class="flex flex-col gap-5">
            <flux:text>{{ __('mfa::mfa.login_sent', ['identifier' => $identifier]) }}</flux:text>

            <flux:input wire:model="secret" :label="__('mfa::mfa.login_code')" inputmode="numeric" autocomplete="one-time-code" autofocus />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('mfa::mfa.login_submit') }}</flux:button>

            <div class="flex justify-between gap-4">
                <flux:link class="cursor-pointer" wire:click.prevent="send">{{ __('mfa::mfa.login_resend') }}</flux:link>
                <flux:link class="cursor-pointer" wire:click.prevent="startOver">{{ __('mfa::mfa.login_other_identifier') }}</flux:link>
            </div>
        </form>
    @elseif ($channel !== '')
        <form wire:submit="{{ $sends ? 'send' : 'signIn' }}" class="flex flex-col gap-5">
            <flux:input wire:model="identifier" :label="__('mfa::mfa.login_identifier')" type="email" autocomplete="username" autofocus />

            @unless ($sends)
                <flux:input
                    wire:model="secret"
                    :label="__('mfa::mfa.login_secret_'.$channel)"
                    :type="in_array($channel, ['password', 'pin'], true) ? 'password' : 'text'"
                    :inputmode="in_array($channel, ['authenticator', 'pin'], true) ? 'numeric' : null"
                    :autocomplete="$channel === 'password' ? 'current-password' : 'one-time-code'"
                />
            @endunless

            <flux:checkbox wire:model="remember" :label="__('mfa::mfa.login_remember')" />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ $sends ? __('mfa::mfa.login_send') : __('mfa::mfa.login_submit') }}
            </flux:button>
        </form>
    @else
        <flux:text>{{ __('mfa::mfa.login_no_channels') }}</flux:text>
    @endif

    @if (count($channels) > 1)
        <flux:separator />

        <div class="flex flex-col gap-2">
            <flux:text size="sm">{{ __('mfa::mfa.login_other_ways') }}</flux:text>

            @foreach ($channels as $name)
                @continue($name === $channel)

                <flux:button size="sm" variant="ghost" wire:click="choose('{{ $name }}')" :key="'channel-'.$name">
                    {{ __('mfa::mfa.channel_'.$name) }}
                </flux:button>
            @endforeach
        </div>
    @endif
</div>
