<?php

namespace App\Filament\App\Resources\SeoActions\Pages;

use App\Filament\App\Resources\SeoActions\SeoActionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeoActions extends ListRecords
{
    protected static string $resource = SeoActionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
