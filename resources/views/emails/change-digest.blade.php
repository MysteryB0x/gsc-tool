<x-mail::message>
# {{ $heading }}

@foreach ($groups as $property => $items)
## {{ $property }}

@foreach ($items as $item)
- **{{ match ($item['kind']) { 'down' => '▼', 'up' => '▲', 'problem' => '!', default => '•' } }} {{ $item['title'] }}**: {{ $item['detail'] }}
@endforeach

@endforeach
<x-mail::button :url="$appUrl">
Open {{ $name }}
</x-mail::button>

You get this because {{ $period }} alerts are on for {{ $team->name }}. [Change your alerts]({{ $settingsUrl }}).
</x-mail::message>
