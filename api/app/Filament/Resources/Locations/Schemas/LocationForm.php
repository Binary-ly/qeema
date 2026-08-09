<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                TextInput::make('admin1_name'),
                TextInput::make('admin1_code'),
                TextInput::make('admin2_name'),
                TextInput::make('admin2_code'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('name_local'),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('latitude')
                    ->numeric(),
                TextInput::make('longitude')
                    ->numeric(),
                TextInput::make('population_estimate')
                    ->numeric(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
