<?php

namespace App\Filament\App\Concerns;

use App\Models\Property;
use App\Support\CurrentProperty;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

/** Header switcher between the workspace's properties; every report page uses the picked one. */
trait PicksProperty
{
    public function property(): ?Property
    {
        return CurrentProperty::get();
    }

    public function switchProperty(int $id): void
    {
        CurrentProperty::set(Filament::getTenant()->properties()->findOrFail($id));
        $this->redirect(request()->header('Referer') ?: static::getUrl());
    }

    protected function propertySwitcher(): ActionGroup
    {
        $current = $this->property();
        $actions = Filament::getTenant()->properties()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Property $p) => Action::make('property_'.$p->id)
                ->label($p->name)
                ->icon($p->id === $current?->id ? Heroicon::Check : null)
                ->action(fn () => $this->switchProperty($p->id)))
            ->all();

        return ActionGroup::make($actions)
            ->label($current?->name ?? 'No property yet')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->button()
            ->color('gray');
    }

    protected function getHeaderActions(): array
    {
        return [$this->propertySwitcher()];
    }

    /** "Reports built 12 Nov 03:10 · data until 9 Nov" */
    public function freshness(): ?string
    {
        $p = $this->property();
        if (! $p) {
            return 'Add a property under Settings → Properties to get started.';
        }

        return collect([
            $p->reports_built_at ? 'Reports built '.$p->reports_built_at->timezone(config('gsctool.timezone'))->format('j M H:i') : 'Reports not built yet',
            $p->synced_until ? 'data until '.$p->synced_until->format('j M Y') : null,
            $p->isBulk() ? 'Bulk Export (full data)' : 'API data',
        ])->filter()->implode(' · ');
    }

    public function getSubheading(): ?string
    {
        return $this->freshness();
    }
}
