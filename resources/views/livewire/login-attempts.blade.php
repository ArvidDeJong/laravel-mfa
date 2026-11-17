<div class="flex w-full flex-col gap-6">
    <flux:heading size="lg">{{ __('mfa::mfa.attempts_title') }}</flux:heading>

    <div class="flex flex-wrap items-end gap-4">
        <flux:input wire:model.live.debounce.400ms="search" :label="__('mfa::mfa.attempts_search')" class="max-w-xs" />
        <flux:checkbox wire:model.live="failedOnly" :label="__('mfa::mfa.attempts_failed_only')" />
    </div>

    <flux:table :paginate="$attempts">
        <flux:table.columns>
            <flux:table.column>{{ __('mfa::mfa.attempts_when') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.attempts_who') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.attempts_guard') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.attempts_how') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.attempts_result') }}</flux:table.column>
            <flux:table.column>{{ __('mfa::mfa.attempts_from') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($attempts as $attempt)
                <flux:table.row :key="'attempt-'.$attempt->id">
                    <flux:table.cell>{{ $attempt->created_at?->isoFormat('LLL') }}</flux:table.cell>
                    <flux:table.cell>{{ $attempt->identifier }}</flux:table.cell>
                    <flux:table.cell>{{ $attempt->guard }}</flux:table.cell>
                    <flux:table.cell>{{ trim(($attempt->channel ?? '').' '.($attempt->method ?? '')) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="in_array($attempt->result, ['succeeded', 'emergency', 'emergency_link_issued'], true) ? 'green' : 'red'">
                            {{ __('mfa::mfa.result_'.$attempt->result) }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono">{{ $attempt->ip }} {{ $attempt->country }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
