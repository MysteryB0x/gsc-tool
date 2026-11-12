<?php

namespace App\Reports;

class CtrCurve
{
    public static function expected(float $position): float
    {
        $curve = config('gsctool.reports.ctr_curve');
        $p = (int) max(1, min(20, round($position)));

        return (float) ($curve[$p] ?? end($curve));
    }

    /** Extra clicks in the window if this query/URL ranked at position 3. */
    public static function potentialAtThree(int $impressions, int $clicks): int
    {
        return max(0, (int) round($impressions * self::expected(3) - $clicks));
    }
}
