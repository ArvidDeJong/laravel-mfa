<div class="flex w-full flex-col gap-8">
    <div class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('mfa::mfa.overview_title') }}</flux:heading>
        <flux:text>{{ __('mfa::mfa.overview_intro') }}</flux:text>
    </div>

    <flux:card class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <flux:heading>{{ __('mfa::mfa.firewall_title') }}</flux:heading>
            <flux:badge :color="$firewall['enabled'] ? 'green' : 'zinc'">{{ $firewall['enabled'] ? __('mfa::mfa.settings_on') : __('mfa::mfa.settings_off') }}</flux:badge>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('mfa::mfa.attempts_guard') }}</flux:table.column>
                <flux:table.column>{{ __('mfa::mfa.overview_channels') }}</flux:table.column>
                <flux:table.column>{{ __('mfa::mfa.overview_countries') }}</flux:table.column>
                <flux:table.column>{{ __('mfa::mfa.overview_outage') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($guards as $row)
                    <flux:table.row :key="'guard-'.$row['guard']">
                        <flux:table.cell class="font-mono">{{ $row['guard'] }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($row['channels'] as $channel)
                                    <flux:badge :color="in_array($channel, $row['skipped'], true) ? 'amber' : 'zinc'">{{ __('mfa::mfa.channel_'.$channel) }}</flux:badge>
                                @empty
                                    {{ __('mfa::mfa.overview_none') }}
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $row['countries'] === [] ? __('mfa::mfa.overview_every_country') : implode(', ', $row['countries']) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$row['resilient'] ? 'green' : 'red'">{{ $row['resilient'] ? __('mfa::mfa.overview_survives') : __('mfa::mfa.overview_at_risk') }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($firewall['allow'] !== [] || $firewall['locations'] !== [] || $firewall['deny'] !== [])
            <flux:text>
                @if ($firewall['allow'] !== []) {{ __('mfa::mfa.firewall_list_allow') }}: <span class="font-mono">{{ implode(', ', $firewall['allow']) }}</span>. @endif
                @if ($firewall['locations'] !== []) {{ __('mfa::mfa.firewall_list_locations') }}: <span class="font-mono">{{ implode(', ', $firewall['locations']) }}</span>. @endif
                @if ($firewall['deny'] !== []) {{ __('mfa::mfa.firewall_list_deny') }}: <span class="font-mono">{{ implode(', ', $firewall['deny']) }}</span>. @endif
            </flux:text>
        @endif
    </flux:card>

    <div class="flex flex-col gap-3">
        <flux:heading>{{ __('mfa::mfa.overview_blocked') }}</flux:heading>

        @if ($blocks->isEmpty())
            <flux:text>{{ __('mfa::mfa.overview_nobody_blocked') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('mfa::mfa.firewall_range') }}</flux:table.column>
                    <flux:table.column>{{ __('mfa::mfa.overview_tried') }}</flux:table.column>
                    <flux:table.column>{{ __('mfa::mfa.overview_since') }}</flux:table.column>
                    <flux:table.column>{{ __('mfa::mfa.firewall_until') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($blocks as $block)
                        <flux:table.row :key="'block-'.$block->id">
                            <flux:table.cell class="font-mono">{{ $block->range }} {{ $overview->country($block) }}</flux:table.cell>
                            <flux:table.cell>{{ implode(', ', $block->identifiers ?? []) ?: __('mfa::mfa.overview_unknown') }}</flux:table.cell>
                            <flux:table.cell>{{ $block->created_at?->isoFormat('LLL') }}</flux:table.cell>
                            <flux:table.cell>{{ $block->expires_at?->isoFormat('LT') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" variant="ghost" wire:click="lift({{ $block->id }})">{{ __('mfa::mfa.firewall_lift') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @if ($pastBlocks->isNotEmpty())
            <flux:heading size="sm">{{ __('mfa::mfa.overview_blocked_before') }}</flux:heading>

            <flux:table>
                <flux:table.rows>
                    @foreach ($pastBlocks as $block)
                        <flux:table.row :key="'past-block-'.$block->id">
                            <flux:table.cell class="font-mono">{{ $block->range }}</flux:table.cell>
                            <flux:table.cell>{{ implode(', ', $block->identifiers ?? []) ?: __('mfa::mfa.overview_unknown') }}</flux:table.cell>
                            <flux:table.cell>{{ $block->created_at?->isoFormat('LLL') }}</flux:table.cell>
                            <flux:table.cell>{{ $block->lifted_at === null ? __('mfa::mfa.overview_ran_out') : __('mfa::mfa.overview_lifted_by', ['who' => $block->lifted_by ?? '?']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    @if ($users !== null)
        <div class="flex flex-col gap-3">
            <flux:heading>{{ __('mfa::mfa.overview_users') }}</flux:heading>

            <div class="flex flex-wrap items-end gap-4">
                @if (count($guards) > 1)
                    <flux:select wire:model.live="usersGuard" :label="__('mfa::mfa.attempts_guard')" class="max-w-xs">
                        @foreach ($guards as $row)
                            <flux:select.option value="{{ $row['guard'] }}" :key="'users-guard-'.$row['guard']">{{ $row['guard'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model.live.debounce.400ms="search" :label="__('mfa::mfa.overview_search')" class="max-w-xs" />
            </div>

            <flux:table :paginate="$users">
                <flux:table.columns>
                    <flux:table.column>{{ __('mfa::mfa.attempts_who') }}</flux:table.column>
                    <flux:table.column>{{ __('mfa::mfa.overview_set_up') }}</flux:table.column>
                    <flux:table.column>{{ __('mfa::mfa.overview_outage') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($users as $row)
                        <flux:table.row :key="'user-'.$guard.'-'.$loop->index">
                            <flux:table.cell>{{ $row['identifier'] }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($row['channels'] as $channel)
                                        <flux:badge>{{ __('mfa::mfa.channel_'.$channel) }}</flux:badge>
                                    @empty
                                        {{ __('mfa::mfa.overview_none') }}
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$row['fallback'] ? 'green' : 'amber'">{{ $row['fallback'] ? __('mfa::mfa.overview_survives') : __('mfa::mfa.overview_no_fallback') }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
