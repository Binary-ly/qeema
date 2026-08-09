<?php

namespace App\Filament\Resources\FxRates\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FxRateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('rate_date')
                    ->date(),
                TextEntry::make('official_rate')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('parallel_rate')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('base_currency'),
                TextEntry::make('source'),
                IconEntry::make('is_manual')
                    ->boolean(),
                TextEntry::make('fetched_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
