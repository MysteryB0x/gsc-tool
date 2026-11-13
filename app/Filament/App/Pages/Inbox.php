<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\PicksProperty;
use App\Models\SeoAction;
use App\Reports\Opportunities;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** All opportunities in one ranked list with a detail pane (layout 2). Can be the home screen (Admin → Branding). */
class Inbox extends Page
{
    use PicksProperty;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Opportunities';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Inbox';

    protected static ?string $title = 'Opportunity inbox';

    protected string $view = 'filament.app.pages.inbox';

    #[Url]
    public ?string $type = null;

    #[Url]
    public ?string $selected = null;

    public function getTitle(): string
    {
        $n = $this->property() ? $this->items()->count() : 0;

        return $n ? "{$n} things to fix, best first" : 'Opportunity inbox';
    }

    public function items()
    {
        return once(fn () => $this->property() ? Opportunities::for($this->property(), $this->type) : collect());
    }

    public function current(): ?array
    {
        $items = $this->items();

        return $items->firstWhere('key', $this->selected) ?? $items->first();
    }

    public function counts(): array
    {
        return $this->property() ? Opportunities::counts($this->property()) : [];
    }

    public function filter(?string $type): void
    {
        $this->type = array_key_exists((string) $type, Opportunities::TYPES) ? $type : null;
        $this->selected = null;
    }

    public function select(string $key): void
    {
        $this->selected = $key;
    }

    public function addAction(string $status = 'open'): void
    {
        $item = $this->current();
        if (! $item) {
            return;
        }

        SeoAction::create([
            'property_id' => $this->property()->id,
            'title' => match ($item['type']) {
                'striking' => 'Push "'.$item['query'].'" into the top 3',
                'decay' => 'Refresh '.Opportunities::path($item['url']),
                'cannibalisation' => 'Pick one page for "'.$item['query'].'"',
                default => 'Rewrite title/meta for "'.$item['query'].'"',
            },
            'url' => $item['url'],
            'query' => $item['query'],
            'source' => $item['type'],
            'status' => $status,
            'notes' => $status === 'open' ? $item['next'] : null,
        ]);

        // Move on to the next item.
        $next = $this->items()->skipUntil(fn ($i) => $i['key'] === $item['key'])->skip(1)->first();
        $this->selected = $next['key'] ?? null;

        Notification::make()->title($status === 'open' ? 'Added to actions' : 'Dismissed')->success()->send();
    }

    public function explorerUrl(?array $item): ?string
    {
        return $item ? Explorer::getUrl(array_filter(['query' => $item['query'], 'page' => $item['query'] ? null : Opportunities::path($item['url'])])) : null;
    }
}
