<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Countries\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CountryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('name_local')
                    ->placeholder('-'),
                TextEntry::make('currency_code'),
                TextEntry::make('currency_symbol')
                    ->placeholder('-'),
                TextEntry::make('currency_minor_units')
                    ->numeric(),
                TextEntry::make('default_locale'),
                TextEntry::make('timezone'),
                TextEntry::make('admin1_label'),
                TextEntry::make('admin2_label')
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
