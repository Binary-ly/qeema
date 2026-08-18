<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshots\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IndexSnapshotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('location.name')
                    ->label('Location'),
                TextEntry::make('basket.name')
                    ->label('Basket'),
                TextEntry::make('snapshot_date')
                    ->date(),
                TextEntry::make('cost_local')
                    ->numeric(),
                TextEntry::make('cost_usd')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('index_level')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('coverage_pct')
                    ->numeric(),
                TextEntry::make('imputed_share')
                    ->numeric(),
                TextEntry::make('ci_low_local')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('ci_high_local')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('fx_rate_used')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('fx_rate_type')
                    ->placeholder('-'),
                TextEntry::make('fx_rate_date')
                    ->date()
                    ->placeholder('-'),
                IconEntry::make('fx_is_stale')
                    ->boolean(),
                TextEntry::make('observed_item_count')
                    ->numeric(),
                TextEntry::make('total_item_count')
                    ->numeric(),
                IconEntry::make('is_stale')
                    ->boolean(),
                TextEntry::make('computed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('model_version')
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
