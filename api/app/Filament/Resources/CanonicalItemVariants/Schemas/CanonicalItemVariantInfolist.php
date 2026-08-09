<?php

namespace App\Filament\Resources\CanonicalItemVariants\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CanonicalItemVariantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('canonicalItem.id')
                    ->label('Canonical item'),
                TextEntry::make('text')
                    ->columnSpanFull(),
                TextEntry::make('normalized_text')
                    ->columnSpanFull(),
                TextEntry::make('locale')
                    ->placeholder('-'),
                TextEntry::make('source'),
                TextEntry::make('createdFromSubmission.id')
                    ->label('Created from submission')
                    ->placeholder('-'),
                TextEntry::make('created_by_user_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('times_matched')
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
