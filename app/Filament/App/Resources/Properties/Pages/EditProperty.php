<?php

namespace App\Filament\App\Resources\Properties\Pages;

use App\Filament\App\Resources\Properties\PropertyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->modalDescription('Removes the property and its reports here. Data already in BigQuery stays until you drop its table.')];
    }
}
