<?php

namespace App\Reports;

use App\Models\Property;
use App\Search\Tables;
use InvalidArgumentException;

/**
 * The SQL behind every report. Each query filters on data_date (the partition), so BigQuery only
 * scans the days asked for. Works on Bulk Export tables and API tables alike (same columns).
 */
class Sql
{
    public const POSITION = 'SAFE_DIVIDE(SUM(sum_position), SUM(impressions)) + 1';

    /** A BigQuery string literal. Values from users only ever enter SQL through here. */
    public static function str(string $value): string
    {
        return "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $value)."'";
    }

    public static function date(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("Not a date: $value");
        }

        return $value;
    }

    public static function dailyTotals(Property $p, string $from, string $to): string
    {
        $t = Tables::urlImpression($p);
        $w = Tables::where(self::date($from), self::date($to));

        return 'SELECT data_date AS date, SUM(clicks) AS clicks, SUM(impressions) AS impressions, '.self::POSITION." AS position
FROM {$t} WHERE {$w}
GROUP BY date ORDER BY date";
    }

    /** Clicks per URL per month, for URLs that reached $minClicks in some month. */
    public static function monthlyUrlClicks(Property $p, string $from, string $to, int $minClicks): string
    {
        $t = Tables::urlImpression($p);
        $w = Tables::where(self::date($from), self::date($to));

        return "WITH m AS (
  SELECT url, FORMAT_DATE('%Y-%m', data_date) AS month, SUM(clicks) AS clicks
  FROM {$t} WHERE {$w}
  GROUP BY url, month
)
SELECT url, month, clicks FROM m
WHERE url IN (SELECT url FROM m GROUP BY url HAVING MAX(clicks) >= {$minClicks})
ORDER BY url, month";
    }

    /** Queries where 2+ URLs each take at least $minShare of the impressions. */
    public static function cannibalisation(Property $p, string $from, string $to, float $minShare, int $minImpressions): string
    {
        $t = Tables::urlImpression($p);
        $w = Tables::where(self::date($from), self::date($to));

        return 'WITH q AS (
  SELECT query, url, SUM(impressions) AS impressions, SUM(clicks) AS clicks, '.self::POSITION." AS position
  FROM {$t} WHERE {$w} AND is_anonymized_query = FALSE AND query IS NOT NULL AND query != ''
  GROUP BY query, url
), tot AS (
  SELECT query, SUM(impressions) AS q_impressions, SUM(clicks) AS q_clicks FROM q GROUP BY query HAVING q_impressions >= {$minImpressions}
)
SELECT q.query, q.url, q.impressions, q.clicks, q.position, SAFE_DIVIDE(q.impressions, tot.q_impressions) AS share, tot.q_impressions, tot.q_clicks
FROM q JOIN tot USING (query)
WHERE SAFE_DIVIDE(q.impressions, tot.q_impressions) >= {$minShare}
QUALIFY COUNT(*) OVER (PARTITION BY q.query) >= 2
ORDER BY tot.q_impressions DESC, q.impressions DESC
LIMIT 10000";
    }

    /** Query + URL pairs in the top 20 with enough impressions: feeds striking distance and CTR. */
    public static function queryUrlWindow(Property $p, string $from, string $to, int $minImpressions): string
    {
        $t = Tables::urlImpression($p);
        $w = Tables::where(self::date($from), self::date($to));

        return 'SELECT query, url, SUM(impressions) AS impressions, SUM(clicks) AS clicks, '.self::POSITION." AS position
FROM {$t} WHERE {$w} AND is_anonymized_query = FALSE AND query IS NOT NULL AND query != ''
GROUP BY query, url
HAVING impressions >= {$minImpressions} AND position <= 20
ORDER BY impressions DESC
LIMIT 20000";
    }
}
