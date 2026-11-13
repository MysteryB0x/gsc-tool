<?php

namespace App\Alerts;

/** One thing worth telling someone about. */
final class Change
{
    public function __construct(
        public string $kind,          // down, up, problem, info
        public string $property,      // property name
        public string $title,         // "Clicks −32%"
        public string $detail,        // "412 yesterday vs 606 a day the week before"
        public ?string $url = null,   // where to look in the app
        public float $weight = 0,     // ranking: bigger first
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
