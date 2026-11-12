<?php

namespace App\Reports;

use App\Models\Property;
use App\Search\Tables;
use InvalidArgumentException;

/**
 * Builds the Explorer's SQL from form choices. Users never type SQL: dimensions and metrics come from
 * fixed lists, and filter values only enter the query as escaped string literals.
 */
class Explorer
{
    public const DIMENSIONS = [
        'query' => 'Query',
        'url' => 'Page',
        'country' => 'Country',
        'device' => 'Device',
        'data_date' => 'Date',
        'month' => 'Month',
    ];

    public const SORTS = ['clicks', 'impressions', 'ctr', 'position'];

    /**
     * @param  list<string>  $dimensions
     * @param  array{query_contains?: ?string, url_contains?: ?string, query_regex?: ?string, country?: ?string, device?: ?string}  $filters
     */
    public static function sql(Property $property, array $dimensions, string $from, string $to, array $filters = [], string $sort = 'clicks', int $limit = 1000): string
    {
        $dimensions = array_values(array_intersect(array_keys(self::DIMENSIONS), $dimensions));
        if (! $dimensions) {
            throw new InvalidArgumentException('Pick at least one dimension.');
        }
        if (! in_array($sort, self::SORTS, true)) {
            $sort = 'clicks';
        }
        $limit = max(1, min(10000, $limit));

        $select = array_map(fn ($d) => $d === 'month' ? "FORMAT_DATE('%Y-%m', data_date) AS month" : $d, $dimensions);
        $where = [Tables::where(Sql::date($from), Sql::date($to))];

        if (in_array('query', $dimensions, true) || filled($filters['query_contains'] ?? null) || filled($filters['query_regex'] ?? null)) {
            $where[] = 'is_anonymized_query = FALSE';
        }
        if (filled($filters['query_contains'] ?? null)) {
            $where[] = 'STRPOS(LOWER(query), LOWER('.Sql::str($filters['query_contains']).')) > 0';
        }
        if (filled($filters['query_regex'] ?? null)) {
            $where[] = 'REGEXP_CONTAINS(query, '.Sql::str($filters['query_regex']).')';
        }
        if (filled($filters['url_contains'] ?? null)) {
            $where[] = 'STRPOS(LOWER(url), LOWER('.Sql::str($filters['url_contains']).')) > 0';
        }
        if (filled($filters['country'] ?? null)) {
            $where[] = 'country = '.Sql::str(strtolower($filters['country']));
        }
        if (filled($filters['device'] ?? null)) {
            $where[] = 'device = '.Sql::str(strtoupper($filters['device']));
        }

        $order = match ($sort) {
            'position' => 'position ASC',
            default => "{$sort} DESC",
        };
        $groupBy = implode(', ', $dimensions);

        return 'SELECT '.implode(', ', $select).', SUM(clicks) AS clicks, SUM(impressions) AS impressions,'
            .' SAFE_DIVIDE(SUM(clicks), SUM(impressions)) AS ctr, '.Sql::POSITION.' AS position'
            ."\nFROM ".Tables::urlImpression($property)
            ."\nWHERE ".implode("\n  AND ", $where)
            ."\nGROUP BY {$groupBy}"
            ."\nORDER BY {$order}"
            ."\nLIMIT {$limit}";
    }
}
