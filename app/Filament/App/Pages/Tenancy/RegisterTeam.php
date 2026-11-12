<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Models\Team;
use App\Services\TeamOnboarding;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;

class RegisterTeam extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create workspace';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Workspace name')->required()->maxLength(100)]);
    }

    protected function handleRegistration(array $data): Team
    {
        return app(TeamOnboarding::class)->createTeam(auth()->user(), $data['name']);
    }
}
