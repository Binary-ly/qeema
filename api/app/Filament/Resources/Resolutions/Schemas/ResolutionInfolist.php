<?php

namespace App\Filament\Resources\Resolutions\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ResolutionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('submission.id')
                    ->label('Submission'),
                TextEntry::make('canonicalItem.id')
                    ->label('Canonical item')
                    ->placeholder('-'),
                TextEntry::make('method'),
                TextEntry::make('confidence')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('reviewed')
                    ->boolean(),
                TextEntry::make('reviewed_by_user_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('reviewed_at')
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
