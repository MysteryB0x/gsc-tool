<?php

namespace App\Alerts;

use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4Daily;
use App\Models\DailyTotal;
use App\Models\Property;
use App\Models\Reports\PageRow;
use App\Models\SeoAction;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Finds big changes for a property from the tables the nightly jobs fill (never BigQuery, no credits).
 * Daily: the newest complete day against the average day of the week before.
 * Weekly: the last 7 days against the 7 before, plus pages that moved and the impact of actions done 4 weeks ago.
 */
class ChangeDetector
{
    public function __construct(protected int $minChange = 20, protected int $minClicks = 50) {}

    /** @return list<Change> */
    public function forProperty(Property $property, string $period): array
    {
        $changes = array_merge(
            $this->problems($property),
            $this->gsc($property, $period),
            $this->ga4($property, $period),
            $period === 'weekly' ? $this->pages($property) : [],
            $period === 'weekly' ? $this->actionImpact($property) : [],
        );
        usort($changes, fn (Change $a, Change $b) => $b->weight <=> $a->weight);

        return $changes;
    }

    /** @return list<Change> */
    public function forTeam(Team $team): array
    {
        $plan = $team->plan;
        if (! $plan || $plan->unlimited_credits || ! $plan->monthly_credits || $team->credit_balance > $plan->monthly_credits * 0.1) {
            return [];
        }

        return [new Change('problem', $team->name, 'Credits running low', "{$team->credit_balance} of {$plan->monthly_credits} credits left this month.", null, 50)];
    }

    /** @return list<Change> */
    protected function problems(Property $p): array
    {
        $out = [];
        if (! $p->has_access && ! $p->isBulk()) {
            $out[] = new Change('problem', $p->name, 'No Search Console access', $p->last_error ?: 'The workspace account can no longer read this property.', null, 100);
        } elseif ($p->last_error) {
            $out[] = new Change('problem', $p->name, 'Sync problem', $p->last_error, null, 90);
        } elseif ($p->synced_until && $p->synced_until->lt(today()->subDays(5))) {
            $out[] = new Change('problem', $p->name, 'Data is late', 'Newest Search Console data is from '.$p->synced_until->format('j M').'.', null, 80);
        }
        if (filled($p->ga4_property_id) && $p->ga4_error) {
            $out[] = new Change('problem', $p->name, 'GA4 problem', $p->ga4_error, null, 70);
        }

        return $out;
    }

    /** @return list<Change> */
    protected function gsc(Property $p, string $period): array
    {
        $end = DailyTotal::where('property_id', $p->id)->max('date');
        if (! $end) {
            return [];
        }
        $end = CarbonImmutable::parse($end);
        [$now, $before, $label] = $this->windows($period, $end, fn ($from, $to) => DailyTotal::where('property_id', $p->id)->whereBetween('date', [$from, $to])
            ->selectRaw('SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) pw')->first());

        $out = [];
        foreach (['c' => 'Clicks', 'i' => 'Impressions'] as $key => $name) {
            // Impressions run ~20–50× clicks: scale the baseline so a small site doesn't alert on them.
            $min = $key === 'c' ? $this->minClicks : $this->minClicks * 20;
            if ($c = $this->compare($p, $name.' (search)', (float) $now->$key, (float) $before->$key, $label, $period, $key === 'c' ? 1.0 : 0.6, $min)) {
                $out[] = $c;
            }
        }

        if ($period === 'weekly' && $now->i > 0 && $before->i > 0 && $before->c * 4 >= $this->minClicks) {
            $posNow = $now->pw / $now->i;
            $posBefore = $before->pw / $before->i;
            if (abs($posNow - $posBefore) >= 1.0) {
                $better = $posNow < $posBefore;
                $out[] = new Change($better ? 'up' : 'down', $p->name, 'Average position '.($better ? 'up' : 'down').' '.number_format(abs($posNow - $posBefore), 1),
                    number_format($posNow, 1).' this week vs '.number_format($posBefore, 1).' the week before.', null, 40);
            }
        }

        return $out;
    }

    /** @return list<Change> */
    protected function ga4(Property $p, string $period): array
    {
        if (blank($p->ga4_property_id) || ! ($end = Ga4Daily::where('property_id', $p->id)->max('date'))) {
            return [];
        }
        $end = CarbonImmutable::parse($end);
        [$now, $before, $label] = $this->windows($period, $end, fn ($from, $to) => Ga4Daily::where('property_id', $p->id)->whereBetween('date', [$from, $to])
            ->selectRaw('SUM(sessions) s, SUM(organic_sessions) o, SUM(key_events) k')->first());
        [$aiNow, $aiBefore] = $this->windows($period, $end, fn ($from, $to) => (int) Ga4AiReferral::where('property_id', $p->id)->whereBetween('date', [$from, $to])->sum('sessions'));

        $out = [];
        foreach ([['s', 'Sessions', 0.8], ['o', 'Organic search sessions', 0.9], ['k', 'Key events', 1.1]] as [$key, $name, $w]) {
            $min = $key === 'k' ? max(3, $this->minClicks / 10) : $this->minClicks;
            if ($c = $this->compare($p, $name, (float) $now->$key, (float) $before->$key, $label, $period, $w, $min)) {
                $out[] = $c;
            }
        }

        // Visits from AI assistants: small numbers, so flag a doubling with at least a handful of visits.
        $perDayBefore = $period === 'daily' ? $aiBefore / 7 : $aiBefore;
        if ($aiNow >= ($period === 'daily' ? 5 : 15) && $aiNow >= 2 * max(1, $perDayBefore)) {
            $out[] = new Change('up', $p->name, 'Visits from AI assistants up', "{$aiNow} {$label['now']} vs ".round($perDayBefore, 1)." {$label['before']}.", null, 45);
        }

        return $out;
    }

    /** Pages whose clicks moved most, last 7 days vs the 7 before. @return list<Change> */
    protected function pages(Property $p): array
    {
        $minDelta = max(10, (int) round($this->minClicks / 5));
        $rows = PageRow::where('property_id', $p->id)->where('clicks_prev_7', '>=', $minDelta)->get()
            ->map(fn ($r) => [$r, $r->clicks_7 - $r->clicks_prev_7, ($r->clicks_7 - $r->clicks_prev_7) / max(1, $r->clicks_prev_7)])
            ->filter(fn ($x) => abs($x[1]) >= $minDelta && abs($x[2]) * 100 >= $this->minChange);

        $out = [];
        foreach ($rows->sortBy(fn ($x) => $x[1])->take(3) as [$r, $d, $pct]) {
            if ($d < 0) {
                $out[] = new Change('down', $p->name, $r->path.' lost '.abs($d).' clicks', "{$r->clicks_7} this week vs {$r->clicks_prev_7} the week before (".round($pct * 100).'%).', $r->url, 30 + min(20, abs($d) / 10));
            }
        }
        foreach ($rows->sortByDesc(fn ($x) => $x[1])->take(3) as [$r, $d, $pct]) {
            if ($d > 0) {
                $out[] = new Change('up', $p->name, $r->path.' gained '.$d.' clicks', "{$r->clicks_7} this week vs {$r->clicks_prev_7} the week before (+".round($pct * 100).'%).', $r->url, 20 + min(20, $d / 10));
            }
        }

        return $out;
    }

    /** Actions marked done 4–5 weeks ago: how their page did over the 28 days since. @return list<Change> */
    protected function actionImpact(Property $p): array
    {
        $out = [];
        $actions = SeoAction::where('property_id', $p->id)->where('status', 'done')->whereNotNull('url')
            ->whereBetween('done_on', [today()->subDays(35), today()->subDays(28)])->get();

        foreach ($actions as $a) {
            $page = PageRow::where('property_id', $p->id)->where('url', $a->url)->first();
            if (! $page || $page->clicks_prev_28 < 10) {
                continue;
            }
            $pct = ($page->clicks_28 - $page->clicks_prev_28) / $page->clicks_prev_28;
            $out[] = new Change('info', $p->name, 'Result of "'.$a->title.'"', 'Page clicks '.($pct >= 0 ? '+' : '').round($pct * 100)."% in the 28 days after ({$page->clicks_28} vs {$page->clicks_prev_28}).", $a->url, 35);
        }

        return $out;
    }

    /**
     * Two windows from one totals callback: daily = newest day vs the average day of the 7 before;
     * weekly = last 7 days vs the 7 before.
     */
    protected function windows(string $period, CarbonImmutable $end, callable $totals): array
    {
        $d = fn (CarbonImmutable $x) => $x->toDateString();

        if ($period === 'daily') {
            return [$totals($d($end), $d($end)), $totals($d($end->subDays(7)), $d($end->subDay())), ['now' => 'on '.$end->format('D j M'), 'before' => 'a day the week before']];
        }

        return [$totals($d($end->subDays(6)), $d($end)), $totals($d($end->subDays(13)), $d($end->subDays(7))), ['now' => 'this week', 'before' => 'the week before']];
    }

    protected function compare(Property $p, string $name, float $now, float $before, array $label, string $period, float $weight, ?float $min = null): ?Change
    {
        $baseline = $period === 'daily' ? $before / 7 : $before;
        $min ??= $this->minClicks;
        // The baseline must be big enough that a % means something (scaled to one day for daily).
        if ($baseline <= 0 || $baseline < ($period === 'daily' ? $min / 7 : $min)) {
            return null;
        }
        $pct = ($now - $baseline) / $baseline;
        if (abs($pct) * 100 < $this->minChange) {
            return null;
        }

        return new Change(
            $pct > 0 ? 'up' : 'down',
            $p->name,
            $name.' '.($pct > 0 ? '+' : '−').round(abs($pct) * 100).'%',
            number_format($now)." {$label['now']} vs ".number_format($baseline, $baseline < 100 ? 1 : 0)." {$label['before']}.",
            null,
            $weight * min(100, abs($pct) * 100) + ($pct < 0 ? 10 : 0),   // drops first
        );
    }
}
