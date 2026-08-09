<?php

namespace App\Filament\Resources\Sources\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                TextInput::make('type')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('url')
                    ->url(),
                TextInput::make('license'),
                TextInput::make('contact'),
                TextInput::make('config'),
                DateTimePicker::make('last_run_at'),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
