@php($primary = \App\Support\Branding::hex('primary_color', '#BA5A00'))
<div style="display: flex; flex-direction: column; gap: 4px; padding: 4px 0; font-size: 12px;">
    @foreach (collect($getState() ?? [])->take(5) as $u)
        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="height: 6px; border-radius: 999px; background: {{ $primary }}; width: {{ max(4, round(($u['share'] ?? 0) * 120)) }}px;"></div>
            <span style="font-variant-numeric: tabular-nums; opacity: .7;">{{ round(($u['share'] ?? 0) * 100) }}%</span>
            <span style="max-width: 20rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $u['url'] }}">{{ parse_url($u['url'], PHP_URL_PATH) ?: $u['url'] }}</span>
            <span style="opacity: .6;">pos {{ $u['position'] }}</span>
        </div>
    @endforeach
</div>
