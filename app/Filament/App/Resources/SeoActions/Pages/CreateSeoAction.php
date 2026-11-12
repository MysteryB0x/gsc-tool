<?php

namespace App\Filament\App\Resources\SeoActions\Pages;

use App\Filament\App\Resources\SeoActions\SeoActionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeoAction extends CreateRecord
{
    protected static string $resource = SeoActionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['source' => 'manual'];
    }
}
