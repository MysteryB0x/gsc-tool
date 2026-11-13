<?php

namespace App\Search;

use App\Models\Property;
use App\Models\Team;

/**
 * One site, however it's written: sc-domain:example.nl, https://www.example.nl/ and http://example.nl/shop/
 * are all "example.nl". Used to keep a site in one workspace.
 */
class SiteKey
{
    public static function of(string $siteUrl): string
    {
        $host = str_starts_with($siteUrl, 'sc-domain:') ? substr($siteUrl, 10) : (string) parse_url($siteUrl, PHP_URL_HOST);

        return preg_replace('/^www\./', '', strtolower(trim($host, ' ./')));
    }

    /** The other workspace that already has this site, when this workspace may not share it. */
    public static function takenBy(string $siteUrl, Team $team, ?int $ignorePropertyId = null): ?Team
    {
        if ($team->may_share_properties) {
            return null;
        }

        return Property::where('site_key', static::of($siteUrl))
            ->where('team_id', '!=', $team->id)
            ->when($ignorePropertyId, fn ($q, $id) => $q->whereKeyNot($id))
            ->with('team')
            ->first()?->team;
    }
}
