@if (empty($items))
    <p style="margin: 0;">Nothing big right now: no digest would be sent.</p>
@else
    <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 10px;">
        @foreach ($items as $item)
            <li style="display: flex; gap: 10px; align-items: baseline;">
                <span aria-hidden="true" style="width: 1rem; color: {{ match ($item['kind']) { 'down', 'problem' => 'var(--danger-600)', 'up' => 'var(--success-600)', default => 'inherit' } }};">{{ match ($item['kind']) { 'down' => '▼', 'up' => '▲', 'problem' => '!', default => '•' } }}</span>
                <span><strong>{{ $item['property'] }}: {{ $item['title'] }}</strong><br><span style="opacity: .75;">{{ $item['detail'] }}</span></span>
            </li>
        @endforeach
    </ul>
@endif
