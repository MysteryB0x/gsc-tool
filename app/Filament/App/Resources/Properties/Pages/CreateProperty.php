<?php

namespace App\Filament\App\Resources\Properties\Pages;

use App\Filament\App\Resources\Properties\PropertyResource;
use App\Search\SyncPlanner;
use Filament\Resources\Pages\CreateRecord;

class CreateProperty extends CreateRecord
{
    protected static string $resource = PropertyResource::class;

    /** New property: check access, then start the 16-month backfill in the background. */
    protected function afterCreate(): void
    {
        $gsc = PropertyResource::checkAccess($this->record);
        $ga4 = filled($this->record->ga4_property_id) && PropertyResource::checkGa4($this->record);

        if ($gsc) {
            app(SyncPlanner::class)->backfill($this->record);   // also queues GA4
        } elseif ($ga4) {
            app(SyncPlanner::class)->ga4($this->record);
        }
    }
}
