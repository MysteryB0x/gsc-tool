<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyTotal;
use App\Models\SeoAction;
use App\Support\Branding;
use App\Support\CurrentProperty;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/** Clicks and impressions per week, with done actions as markers. */
class ClicksTrend extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Clicks and impressions';

    protected ?string $description = 'Per week. Triangles mark actions you marked done.';

    protected ?string $maxHeight = '320px';

    public ?string $filter = '6';

    protected function getFilters(): ?array
    {
        return ['3' => 'Last 3 months', '6' => 'Last 6 months', '16' => 'Last 16 months'];
    }

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

        $from = now()->subMonths((int) $this->filter)->startOfWeek();
        $weeks = DailyTotal::where('property_id', $property->id)->where('date', '>=', $from->toDateString())->orderBy('date')->get()
            ->groupBy(fn ($d) => $d->date->startOfWeek()->toDateString())
            ->map(fn ($days) => ['clicks' => $days->sum('clicks'), 'impressions' => $days->sum('impressions')]);

        $actions = SeoAction::where('property_id', $property->id)->where('status', 'done')->where('done_on', '>=', $from)->get()
            ->groupBy(fn ($a) => $a->done_on->startOfWeek()->toDateString());

        $labels = $weeks->keys()->map(fn ($w) => Carbon::parse($w)->format('j M'))->values()->all();

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Clicks', 'data' => $weeks->pluck('clicks')->values()->all(), 'borderColor' => Branding::hex('primary_color', '#BA5A00'), 'backgroundColor' => Branding::hex('primary_color', '#BA5A00'), 'yAxisID' => 'y', 'tension' => 0.25, 'pointRadius' => 0],
                ['label' => 'Impressions', 'data' => $weeks->pluck('impressions')->values()->all(), 'borderColor' => '#94a3b8', 'backgroundColor' => '#94a3b8', 'yAxisID' => 'y1', 'tension' => 0.25, 'pointRadius' => 0, 'borderDash' => [4, 4]],
                ['label' => 'Actions done', 'data' => $weeks->map(fn ($v, $w) => $actions->has($w) ? $v['clicks'] : null)->values()->all(),
                    'yAxisID' => 'y', 'showLine' => false, 'pointStyle' => 'triangle', 'pointRadius' => 8, 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Clicks']],
                'y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Impressions']],
            ],
        ];
    }
}
