<?php

namespace App\Search;

use App\Models\Property;

/**
 * Where a property's raw search data lives in BigQuery. API-synced properties get their own table in
 * the gsc_api dataset with the same columns as Google's Bulk Export (searchdata_url_impression), so
 * every report query runs unchanged on either source.
 */
class Tables
{
    /** Columns of the API table — the Bulk Export url_impression shape (position = sum_position / impressions + 1). */
    public const SCHEMA = [
        ['name' => 'data_date', 'type' => 'DATE', 'mode' => 'REQUIRED'],
        ['name' => 'site_url', 'type' => 'STRING'],
        ['name' => 'url', 'type' => 'STRING'],
        ['name' => 'query', 'type' => 'STRING'],
        ['name' => 'is_anonymized_query', 'type' => 'BOOLEAN'],
        ['name' => 'country', 'type' => 'STRING'],
        ['name' => 'search_type', 'type' => 'STRING'],
        ['name' => 'device', 'type' => 'STRING'],
        ['name' => 'impressions', 'type' => 'INTEGER'],
        ['name' => 'clicks', 'type' => 'INTEGER'],
        ['name' => 'sum_position', 'type' => 'FLOAT'],
    ];

    public static function apiDataset(): string
    {
        return config('gsctool.bigquery.api_dataset', 'gsc_api');
    }

    public static function apiTable(Property $property): string
    {
        return 'url_impression_p'.$property->id;
    }

    /** Fully qualified, backticked table for SQL. */
    public static function urlImpression(Property $property): string
    {
        $project = config('gsctool.bigquery.project');

        return $property->isBulk()
            ? "`{$project}.{$property->bq_dataset}.searchdata_url_impression`"
            : "`{$project}.".static::apiDataset().'.'.static::apiTable($property).'`';
    }

    /** The WHERE clause every report adds: web search only, inside the date range (partition filter). */
    public static function where(string $from, string $to): string
    {
        return "search_type = 'WEB' AND data_date BETWEEN DATE '{$from}' AND DATE '{$to}'";
    }
}
