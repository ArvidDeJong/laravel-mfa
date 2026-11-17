<div class="flex w-full flex-col gap-8">
    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-3">
            <flux:heading size="lg">{{ __('mfa::mfa.firewall_title') }}</flux:heading>
            <flux:badge :color="$enabled ? 'green' : 'zinc'">{{ $enabled ? __('mfa::mfa.settings_on') : __('mfa::mfa.settings_off') }}</flux:badge>
        </div>
        <flux:text>{{ __('mfa::mfa.firewall_intro', ['ip' => $yourIp]) }}</flux:text>
    </div>

    @if ($canManage)
    <flux:card>
        <form wire:submit="add" class="flex flex-col gap-4">
            <flux:heading>{{ __('mfa::mfa.firewall_add') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="action" :label="__('mfa::mfa.firewall_action')">
                    <flux:select.option value="allow">{{ __('mfa::mfa.firewall_allow') }}</flux:select.option>
                    <flux:select.option value="deny">{{ __('mfa::mfa.firewall_deny') }}</flux:select.option>
                </flux:select>

                <flux:input wire:model="range" :label="__('mfa::mfa.firewall_range')" placeholder="198.51.100.0/24" />
                <flux:input wire:model="note" :label="__('mfa::mfa.firewall_note')" />
                <flux:input wire:model="until" :label="__('mfa::mfa.firewall_until')" type="date" />

                @if (count($guards) > 1)
                    <flux:select wire:model="ruleGuard" :label="__('mfa::mfa.firewall_guard')">
                        <flux:select.option value="">{{ __('mfa::mfa.firewall_every_guard') }}</flux:select.option>
                        @foreach ($guards as $guard)
                            <flux:select.option value="{{ $guard }}" :key="'guard-'.$guard">{{ $guard }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            @if ($action === 'allow')
                <div class="flex flex-col gap-2">
                    <flux:checkbox wire:model="isLocation" :label="__('mfa::mfa.firewall_is_location')" />
                    <flux:checkbox wire:model="allowsPin" :label="__('mfa::mfa.firewall_allows_pin')" />
                    <flux:checkbox wire:model="neverBlock" :label="__('mfa::mfa.firewall_never_block')" />
                </div>
            @endif

            <div>
                <flux:button type="submit" variant="primary">{{ __('mfa::mfa.firewall_add') }}</flux:button>
            </div>
        </form>
    </flux:card>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('mfa::mfa.firewall_range') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.firewall_action') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.firewall_note') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.firewall_until') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($fixed as $list => $ranges)
                @foreach ($ranges as $fixedRange)
                    <flux:table.row :key="'fixed-'.$list.'-'.$loop->index">
                        <flux:table.cell class="font-mono">{{ $fixedRange }}</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$list === 'deny' ? 'red' : 'green'">{{ __('mfa::mfa.firewall_list_'.$list) }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ __('mfa::mfa.firewall_from_config') }}</flux:table.cell>
                        <flux:table.cell></flux:table.cell>
                        <flux:table.cell></flux:table.cell>
                    </flux:table.row>
                @endforeach
            @endforeach

            @foreach ($rules as $rule)
                <flux:table.row :key="'rule-'.$rule->id">
                    <flux:table.cell class="font-mono">{{ $rule->range }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            <flux:badge :color="$rule->action === 'deny' ? 'red' : 'green'">
                                {{ $rule->automatic ? __('mfa::mfa.firewall_blocked') : __('mfa::mfa.firewall_'.$rule->action) }}
                            </flux:badge>
                            @if ($rule->is_location)<flux:badge>{{ __('mfa::mfa.firewall_badge_location') }}</flux:badge>@endif
                            @if ($rule->allows_pin)<flux:badge>{{ __('mfa::mfa.channel_pin') }}</flux:badge>@endif
                            @if ($rule->never_block)<flux:badge>{{ __('mfa::mfa.firewall_badge_never_block') }}</flux:badge>@endif
                            @if ($rule->guard)<flux:badge color="zinc">{{ $rule->guard }}</flux:badge>@endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $rule->automatic ? __('mfa::mfa.blocked_attempts') : $rule->note }}</flux:table.cell>
                    <flux:table.cell>{{ $rule->expires_at?->isoFormat('LLL') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="ghost" wire:click="remove({{ $rule->id }})" wire:confirm="{{ __('mfa::mfa.settings_remove_confirm') }}">
                            {{ $rule->automatic ? __('mfa::mfa.firewall_lift') : __('mfa::mfa.settings_remove') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
