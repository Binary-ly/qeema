<?php

namespace App\Filament\Resources\Submissions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('location.name')
                    ->label('Location'),
                TextEntry::make('reporter.id')
                    ->label('Reporter')
                    ->placeholder('-'),
                TextEntry::make('source.name')
                    ->label('Source'),
                TextEntry::make('ingestionBatch.id')
                    ->label('Ingestion batch')
                    ->placeholder('-'),
                TextEntry::make('raw_text')
                    ->columnSpanFull(),
                TextEntry::make('raw_price')
                    ->money(),
                TextEntry::make('currency_code'),
                TextEntry::make('raw_unit')
                    ->placeholder('-'),
                TextEntry::make('raw_quantity')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('photo_path')
                    ->placeholder('-'),
                TextEntry::make('observed_at')
                    ->dateTime(),
                TextEntry::make('collected_at')
                    ->dateTime(),
                TextEntry::make('ingested_at')
                    ->dateTime(),
                TextEntry::make('client_idempotency_key')
                    ->placeholder('-'),
                TextEntry::make('status'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
