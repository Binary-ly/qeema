<?php

namespace App\Filament\Resources\IndexSnapshotItems\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IndexSnapshotItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('indexSnapshot.id')
                    ->label('Index snapshot'),
                TextEntry::make('canonicalItem.id')
                    ->label('Canonical item'),
                TextEntry::make('unit_price_local')
                    ->numeric(),
                TextEntry::make('weight')
                    ->numeric(),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('contribution_local')
                    ->numeric(),
                IconEntry::make('is_imputed')
                    ->boolean(),
                TextEntry::make('imputation_method')
                    ->placeholder('-'),
                TextEntry::make('ci_low')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('ci_high')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('observation_count')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
