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
        if (PropertyResource::checkAccess($this->record)) {
            app(SyncPlanner::class)->backfill($this->record);
        }
    }
}
