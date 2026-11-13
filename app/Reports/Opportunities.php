<?php

namespace App\Reports;

use App\Models\Property;
use App\Models\Reports\CannibalisationRow;
use App\Models\Reports\CtrRow;
use App\Models\Reports\DecayRow;
use App\Models\Reports\StrikingRow;
use App\Models\SeoAction;
use Illuminate\Support\Collection;

/**
 * Every report row as one kind of item, ranked by clicks at stake: the opportunity inbox.
 * Reads the pre-computed report tables only (no BigQuery, no credits).
 */
class Opportunities
{
    public const TYPES = [
        'striking' => 'Striking distance',
        'decay' => 'Content decay',
        'cannibalisation' => 'Cannibalisation',
        'ctr' => 'CTR below expected',
    ];

    /** @return Collection<int, array<string, mixed>> */
    public static function for(Property $property, ?string $type = null, int $limit = 300): Collection
    {
        $handled = SeoAction::where('property_id', $property->id)->whereIn('status', ['open', 'done', 'dismissed'])
            ->get(['source', 'url', 'query', 'status'])
            ->mapWithKeys(fn ($a) => [static::key($a->source, $a->url, $a->query) => $a->status]);

        $items = collect();

        if (! $type || $type === 'striking') {
            $items = $items->concat(StrikingRow::where('property_id', $property->id)->orderByDesc('potential_clicks')->limit($limit)->get()->map(fn ($r) => [
                'type' => 'striking', 'id' => $r->id, 'title' => $r->query, 'url' => $r->url, 'query' => $r->query,
                'meta' => static::path($r->url).' · pos. '.number_format($r->position, 1),
                'value' => $r->potential_clicks, 'value_label' => '+'.number_format($r->potential_clicks),
                'stats' => ['Position' => number_format($r->position, 1), 'Impressions' => number_format($r->impressions), 'Clicks' => number_format($r->clicks), 'Extra clicks at pos. 3' => '+'.number_format($r->potential_clicks)],
                'next' => 'Use the query in the title and H1, answer it higher on the page, and link to the page from related pages with that anchor text.',
            ]));
        }

        if (! $type || $type === 'decay') {
            $items = $items->concat(DecayRow::where('property_id', $property->id)->orderBy('change')->limit($limit)->get()->map(fn ($r) => [
                'type' => 'decay', 'id' => $r->id, 'title' => static::path($r->url), 'url' => $r->url, 'query' => null,
                'meta' => number_format($r->peak_clicks).' → '.number_format($r->last_clicks).' clicks a month · '.round($r->change * 100).'%',
                'value' => $r->peak_clicks - $r->last_clicks, 'value_label' => '−'.number_format($r->peak_clicks - $r->last_clicks),
                'stats' => ['Peak month' => number_format($r->peak_clicks), 'Last month' => number_format($r->last_clicks), 'Change' => round($r->change * 100).'%'],
                'series' => $r->monthly_clicks,
                'next' => 'Check what changed (content, title, internal links, a competitor), refresh the facts and dates, and fill the gaps for the queries it lost.',
            ]));
        }

        if (! $type || $type === 'cannibalisation') {
            $items = $items->concat(CannibalisationRow::where('property_id', $property->id)->orderByDesc('impressions')->limit($limit)->get()->map(fn ($r) => [
                'type' => 'cannibalisation', 'id' => $r->id, 'title' => $r->query, 'url' => $r->urls[0]['url'] ?? null, 'query' => $r->query,
                'meta' => $r->url_count.' pages split '.number_format($r->impressions).' impressions',
                // Roughly what one clear page in the top 3 would win over the split.
                'value' => max(0, (int) round($r->impressions * CtrCurve::expected(3) - $r->clicks)),
                'value_label' => '+'.number_format(max(0, (int) round($r->impressions * CtrCurve::expected(3) - $r->clicks))),
                'stats' => ['Impressions' => number_format($r->impressions), 'Clicks' => number_format($r->clicks), 'Pages' => $r->url_count],
                'urls' => $r->urls,
                'next' => 'Pick one page for this query. Merge or redirect the weaker one, or make each page target a different intent and link between them.',
            ]));
        }

        if (! $type || $type === 'ctr') {
            $items = $items->concat(CtrRow::where('property_id', $property->id)->orderByDesc('missed_clicks')->limit($limit)->get()->map(fn ($r) => [
                'type' => 'ctr', 'id' => $r->id, 'title' => $r->query, 'url' => $r->url, 'query' => $r->query,
                'meta' => 'pos. '.number_format($r->position, 1).' · CTR '.round($r->ctr * 100, 1).'% vs '.round($r->expected_ctr * 100, 1).'% expected',
                'value' => $r->missed_clicks, 'value_label' => '+'.number_format($r->missed_clicks),
                'stats' => ['Position' => number_format($r->position, 1), 'CTR' => round($r->ctr * 100, 2).'%', 'Expected' => round($r->expected_ctr * 100, 1).'%', 'Missed clicks' => number_format($r->missed_clicks)],
                'next' => 'Rewrite the title and meta description around the query and what makes the page the best answer; check rich results.',
            ]));
        }

        return $items
            ->map(fn ($i) => $i + ['key' => $i['type'].':'.$i['id'], 'status' => $handled[static::key($i['type'], $i['url'], $i['query'])] ?? null])
            ->reject(fn ($i) => $i['status'] === 'dismissed')
            ->sortByDesc('value')
            ->take($limit)
            ->values();
    }

    /** @return array<string, int> */
    public static function counts(Property $property): array
    {
        return [
            'striking' => StrikingRow::where('property_id', $property->id)->count(),
            'decay' => DecayRow::where('property_id', $property->id)->count(),
            'cannibalisation' => CannibalisationRow::where('property_id', $property->id)->count(),
            'ctr' => CtrRow::where('property_id', $property->id)->count(),
        ];
    }

    public static function key(?string $source, ?string $url, ?string $query): string
    {
        return ($source ?? '').'|'.($url ?? '').'|'.($query ?? '');
    }

    public static function path(?string $url): string
    {
        return $url ? (parse_url($url, PHP_URL_PATH) ?: $url) : '—';
    }
}
