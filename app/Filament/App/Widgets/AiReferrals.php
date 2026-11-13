<?php

namespace App\Filament\App\Widgets;

use App\Models\Analytics\Ga4AiReferral;
use App\Support\CurrentProperty;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

class AiReferrals extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = now()->subDays(28)->toDateString();

        return $table
            ->heading('Visits from AI assistants, last 28 days')
            ->description('Sessions GA4 attributes to ChatGPT, Perplexity, Gemini, Copilot, Claude and others.')
            ->query(Ga4AiReferral::query()
                ->where('property_id', CurrentProperty::get()?->id ?? 0)
                ->where('date', '>=', $since)
                ->select('source', DB::raw('MIN(id) as id'), DB::raw('SUM(sessions) as sessions'), DB::raw('SUM(key_events) as key_events'))
                ->groupBy('source'))
            ->defaultSort('sessions', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('source'),
                TextColumn::make('sessions')->numeric()->sortable()->alignEnd(),
                TextColumn::make('key_events')->label('Key events')->numeric()->sortable()->alignEnd(),
            ])
            ->emptyStateHeading('No visits from AI assistants yet');
    }
}
