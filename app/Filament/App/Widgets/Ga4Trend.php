<?php

namespace App\Filament\App\Widgets;

use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4Daily;
use App\Support\Branding;
use App\Support\CurrentProperty;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class Ga4Trend extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Sessions per week';

    protected ?string $description = 'All sessions and Organic Search (left), sessions from AI assistants (right).';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $property = CurrentProperty::get();
        if (! $property) {
            return ['datasets' => [], 'labels' => []];
        }
        $from = now()->subMonths(6)->startOfWeek()->toDateString();
        $week = fn ($d) => $d->date->startOfWeek()->toDateString();

        $days = Ga4Daily::where('property_id', $property->id)->where('date', '>=', $from)->orderBy('date')->get()->groupBy($week);
        $ai = Ga4AiReferral::where('property_id', $property->id)->where('date', '>=', $from)->get()->groupBy($week);
        $primary = Branding::hex('primary_color', '#BA5A00');

        return [
            'labels' => $days->keys()->map(fn ($w) => Carbon::parse($w)->format('j M'))->values()->all(),
            'datasets' => [
                ['label' => 'Sessions', 'data' => $days->map->sum('sessions')->values()->all(), 'borderColor' => '#5b6170', 'backgroundColor' => '#5b6170', 'borderDash' => [4, 4], 'pointRadius' => 0, 'tension' => 0.25, 'yAxisID' => 'y'],
                ['label' => 'Organic search', 'data' => $days->map->sum('organic_sessions')->values()->all(), 'borderColor' => $primary, 'backgroundColor' => $primary, 'pointRadius' => 0, 'tension' => 0.25, 'yAxisID' => 'y'],
                ['label' => 'AI assistants', 'data' => $days->keys()->map(fn ($w) => $ai->get($w)?->sum('sessions') ?? 0)->values()->all(), 'borderColor' => '#0066cc', 'backgroundColor' => '#0066cc', 'pointRadius' => 0, 'tension' => 0.25, 'yAxisID' => 'y1'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Sessions']],
                'y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'AI assistant sessions']],
            ],
        ];
    }
}
