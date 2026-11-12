<div class="space-y-1 py-1 text-xs">
    @foreach (collect($getState() ?? [])->take(5) as $u)
        <div class="flex items-center gap-2">
            <div class="h-1.5 rounded-full bg-primary-500" style="width: {{ max(4, round(($u['share'] ?? 0) * 120)) }}px"></div>
            <span class="text-gray-500 dark:text-gray-400 tabular-nums">{{ round(($u['share'] ?? 0) * 100) }}%</span>
            <span class="truncate max-w-xs" title="{{ $u['url'] }}">{{ parse_url($u['url'], PHP_URL_PATH) ?: $u['url'] }}</span>
            <span class="text-gray-400">pos {{ $u['position'] }}</span>
        </div>
    @endforeach
</div>
