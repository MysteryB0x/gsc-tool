<?php

namespace App\Support;

use App\Models\Property;
use Filament\Facades\Filament;

/** The property picked in the header, remembered per workspace for the session. */
class CurrentProperty
{
    public static function key(): string
    {
        return 'current_property.'.(Filament::getTenant()?->getKey() ?? 0);
    }

    public static function get(): ?Property
    {
        $team = Filament::getTenant();
        if (! $team) {
            return null;
        }
        $id = session(static::key());

        return ($id ? $team->properties()->whereKey($id)->first() : null)
            ?? $team->properties()->where('is_active', true)->orderBy('name')->first();
    }

    public static function set(Property $property): void
    {
        abort_unless($property->team_id === Filament::getTenant()?->getKey(), 403);
        session([static::key() => $property->id]);
    }
}
