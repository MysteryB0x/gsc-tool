<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Search\SiteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Search Console property. Its raw data is in BigQuery; its report results are in the report_* tables. */
class Property extends Model
{
    use HasFactory;

    public const SOURCE_API = 'api';

    public const SOURCE_BULK = 'bulk';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'has_access' => 'boolean',
            'is_active' => 'boolean',
            'access_checked_at' => 'datetime',
            'synced_until' => DateOnly::class,
            'backfilled_from' => DateOnly::class,
            'last_synced_at' => 'datetime',
            'reports_built_at' => 'datetime',
            'ga4_has_access' => 'boolean',
            'ga4_synced_until' => DateOnly::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Property $p) => $p->site_key = SiteKey::of($p->site_url));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function dailyTotals(): HasMany
    {
        return $this->hasMany(DailyTotal::class);
    }

    public function seoActions(): HasMany
    {
        return $this->hasMany(SeoAction::class);
    }

    public function isBulk(): bool
    {
        return $this->source === self::SOURCE_BULK;
    }

    /** sc-domain:example.nl → example.nl; https://www.example.nl/ → www.example.nl */
    public function host(): string
    {
        return str_starts_with($this->site_url, 'sc-domain:')
            ? substr($this->site_url, 10)
            : (string) parse_url($this->site_url, PHP_URL_HOST);
    }
}
