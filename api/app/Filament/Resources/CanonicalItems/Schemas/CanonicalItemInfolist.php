<?php

namespace App\Filament\Resources\CanonicalItems\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CanonicalItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('code'),
                TextEntry::make('name_en'),
                TextEntry::make('name_local')
                    ->placeholder('-'),
                TextEntry::make('category'),
                TextEntry::make('default_unit_code'),
                TextEntry::make('default_quantity')
                    ->numeric(),
                TextEntry::make('embedding')
                    ->placeholder('-'),
                TextEntry::make('embedding_model')
                    ->placeholder('-'),
                TextEntry::make('embedding_updated_at')
                    ->dateTime()
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
