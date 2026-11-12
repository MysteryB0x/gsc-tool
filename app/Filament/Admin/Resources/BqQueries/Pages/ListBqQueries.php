<?php

namespace App\Filament\Admin\Resources\BqQueries\Pages;

use App\Filament\Admin\Resources\BqQueries\BqQueryResource;
use Filament\Resources\Pages\ListRecords;

class ListBqQueries extends ListRecords
{
    protected static string $resource = BqQueryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
