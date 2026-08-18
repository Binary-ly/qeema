<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class LocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('admin1_name')
                    ->placeholder('-'),
                TextEntry::make('admin1_code')
                    ->placeholder('-'),
                TextEntry::make('admin2_name')
                    ->placeholder('-'),
                TextEntry::make('admin2_code')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('name_local')
                    ->placeholder('-'),
                TextEntry::make('slug'),
                TextEntry::make('latitude')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('longitude')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('population_estimate')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
