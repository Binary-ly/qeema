<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PriceObservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('submission.id')
                    ->label('Submission'),
                TextEntry::make('country_id')
                    ->numeric(),
                TextEntry::make('location.name')
                    ->label('Location'),
                TextEntry::make('canonicalItem.id')
                    ->label('Canonical item'),
                TextEntry::make('price')
                    ->money(),
                TextEntry::make('currency_code'),
                TextEntry::make('unit_code'),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('normalized_price_per_base_unit')
                    ->numeric(),
                TextEntry::make('observed_on')
                    ->date(),
                TextEntry::make('observed_at')
                    ->dateTime(),
                TextEntry::make('reporter.id')
                    ->label('Reporter')
                    ->placeholder('-'),
                TextEntry::make('source_id')
                    ->numeric(),
                TextEntry::make('reputation_at_time')
                    ->numeric(),
                IconEntry::make('is_valid')
                    ->boolean(),
                TextEntry::make('supersededBy.id')
                    ->label('Superseded by')
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
