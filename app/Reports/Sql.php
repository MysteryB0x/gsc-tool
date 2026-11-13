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

    /** Clicks per URL: last 7 days vs the 7 before, last 28 vs the 28 before (window ends on $to). */
    public static function pageTotals(Property $p, string $to): string
    {
        $t = Tables::urlImpression($p);
        $end = self::date($to);
        $w = Tables::where(date('Y-m-d', strtotime("$end -55 days")), $end);

        return "SELECT url,
  SUM(IF(data_date > DATE_SUB(DATE '{$end}', INTERVAL 7 DAY), clicks, 0)) AS clicks_7,
  SUM(IF(data_date <= DATE_SUB(DATE '{$end}', INTERVAL 7 DAY) AND data_date > DATE_SUB(DATE '{$end}', INTERVAL 14 DAY), clicks, 0)) AS clicks_prev_7,
  SUM(IF(data_date > DATE_SUB(DATE '{$end}', INTERVAL 28 DAY), clicks, 0)) AS clicks_28,
  SUM(IF(data_date <= DATE_SUB(DATE '{$end}', INTERVAL 28 DAY), clicks, 0)) AS clicks_prev_28,
  SUM(IF(data_date > DATE_SUB(DATE '{$end}', INTERVAL 28 DAY), impressions, 0)) AS impressions_28,
  SAFE_DIVIDE(SUM(IF(data_date > DATE_SUB(DATE '{$end}', INTERVAL 28 DAY), sum_position, 0)), SUM(IF(data_date > DATE_SUB(DATE '{$end}', INTERVAL 28 DAY), impressions, 0))) + 1 AS position_28
FROM {$t} WHERE {$w}
GROUP BY url
HAVING clicks_28 + clicks_prev_28 >= 5
ORDER BY clicks_28 DESC
LIMIT 20000";
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
