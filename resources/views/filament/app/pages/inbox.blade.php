@php
    $items = $this->items();
    $current = $this->current();
    $counts = $this->counts();
    $line = 'color-mix(in oklab, currentColor 14%, transparent)';
    $soft = 'color-mix(in oklab, var(--primary-500) 12%, transparent)';
@endphp
<x-filament-panels::page>
    @if (! $this->property())
        <x-filament::section>Add a property under Settings → Properties to get started.</x-filament::section>
    @else
        <div role="group" aria-label="Filter by report" style="display: flex; flex-wrap: wrap; gap: 6px;">
            <x-filament::button size="sm" :color="$type ? 'gray' : 'primary'" wire:click="filter(null)">All · {{ array_sum($counts) }}</x-filament::button>
            @foreach (\App\Reports\Opportunities::TYPES as $key => $label)
                <x-filament::button size="sm" :color="$type === $key ? 'primary' : 'gray'" wire:click="filter('{{ $key }}')">{{ $label }} · {{ $counts[$key] ?? 0 }}</x-filament::button>
            @endforeach
        </div>

        @if ($items->isEmpty())
            <x-filament::section>Nothing here yet. Reports are built nightly after the first sync.</x-filament::section>
        @else
            <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start;">
                <x-filament::section style="flex: 1 1 360px; min-width: 0; max-height: 75vh; overflow-y: auto;" :compact="true">
                    <ul style="list-style: none; margin: -12px; padding: 0;" aria-label="Opportunities">
                        @foreach ($items as $item)
                            @php($on = $current && $current['key'] === $item['key'])
                            <li style="border-bottom: 1px solid {{ $line }};">
                                <button type="button" wire:click="select('{{ $item['key'] }}')" @if ($on) aria-current="true" @endif
                                    style="width: 100%; text-align: left; display: flex; gap: 12px; padding: 12px 14px; border: 0; cursor: pointer; color: inherit; font: inherit; background: {{ $on ? $soft : 'transparent' }}; box-shadow: {{ $on ? 'inset 3px 0 0 var(--primary-600)' : 'none' }};">
                                    <span style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px;">
                                        <span style="font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .7;">{{ \App\Reports\Opportunities::TYPES[$item['type']] }}@if ($item['status']) · action {{ $item['status'] }}@endif</span>
                                        <span style="font-weight: 600; overflow-wrap: anywhere;">{{ $item['title'] }}</span>
                                        <span style="font-size: 13px; opacity: .75; overflow-wrap: anywhere;">{{ $item['meta'] }}</span>
                                    </span>
                                    <span style="font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; color: {{ $item['type'] === 'decay' ? 'var(--danger-600)' : 'var(--success-600)' }};">{{ $item['value_label'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>

                @if ($current)
                    <x-filament::section style="flex: 999 1 520px; min-width: 0;">
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--primary-600);">{{ \App\Reports\Opportunities::TYPES[$current['type']] }}</span>
                                <h2 style="margin: 0; font-size: 22px; font-weight: 700; overflow-wrap: anywhere;">{{ $current['title'] }}</h2>
                                @if ($current['url'])<a href="{{ $current['url'] }}" target="_blank" rel="noopener" style="color: var(--primary-600); overflow-wrap: anywhere;">{{ $current['url'] }}</a>@endif
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                                @foreach ($current['stats'] as $label => $value)
                                    <div style="border: 1px solid {{ $line }}; border-radius: 10px; padding: 10px 12px; display: flex; flex-direction: column; gap: 2px;">
                                        <span style="font-size: 12px; opacity: .7;">{{ $label }}</span>
                                        <span style="font-size: 20px; font-variant-numeric: tabular-nums;">{{ $value }}</span>
                                    </div>
                                @endforeach
                            </div>

                            @if (! empty($current['series']))
                                @php($max = max(1, max($current['series'])))
                                <div>
                                    <h3 style="margin: 0 0 8px; font-size: 14px; font-weight: 600;">Clicks per month</h3>
                                    <div style="display: flex; align-items: flex-end; gap: 6px; height: 110px;" role="img" aria-label="Clicks per month from {{ array_key_first($current['series']) }} to {{ array_key_last($current['series']) }}">
                                        @foreach ($current['series'] as $month => $clicks)
                                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;">
                                                <div style="width: 100%; border-radius: 4px 4px 0 0; background: var(--primary-500); height: {{ max(2, round($clicks / $max * 90)) }}px;" title="{{ $month }}: {{ $clicks }}"></div>
                                                <span style="font-size: 10px; opacity: .6;">{{ \Illuminate\Support\Carbon::parse($month.'-01')->format('M') }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (! empty($current['urls']))
                                <div>
                                    <h3 style="margin: 0 0 8px; font-size: 14px; font-weight: 600;">Pages competing for this query</h3>
                                    <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px;">
                                        @foreach ($current['urls'] as $u)
                                            <li style="display: flex; gap: 10px; align-items: center;">
                                                <span style="width: 44px; font-variant-numeric: tabular-nums;">{{ round($u['share'] * 100) }}%</span>
                                                <span style="flex: 1; overflow-wrap: anywhere;">{{ \App\Reports\Opportunities::path($u['url']) }}</span>
                                                <span style="opacity: .7; white-space: nowrap;">pos. {{ $u['position'] }} · {{ number_format($u['clicks']) }} clicks</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div style="border: 1px solid {{ $line }}; border-radius: 10px; padding: 12px 14px;">
                                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">Suggested next step</h3>
                                <p style="margin: 0; line-height: 1.55;">{{ $current['next'] }}</p>
                            </div>

                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <x-filament::button wire:click="addAction('open')" icon="heroicon-o-plus-circle" :disabled="(bool) $current['status']">{{ $current['status'] ? 'Already an action' : 'Add as action' }}</x-filament::button>
                                <x-filament::button tag="a" :href="$this->explorerUrl($current)" color="gray" icon="heroicon-o-magnifying-glass">Open in Explorer</x-filament::button>
                                <x-filament::button wire:click="addAction('dismissed')" color="gray" outlined>Dismiss</x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
