<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches;

use App\Filament\Resources\IngestionBatches\Pages\CreateIngestionBatch;
use App\Filament\Resources\IngestionBatches\Pages\EditIngestionBatch;
use App\Filament\Resources\IngestionBatches\Pages\ListIngestionBatches;
use App\Filament\Resources\IngestionBatches\Pages\ViewIngestionBatch;
use App\Filament\Resources\IngestionBatches\Schemas\IngestionBatchForm;
use App\Filament\Resources\IngestionBatches\Schemas\IngestionBatchInfolist;
use App\Filament\Resources\IngestionBatches\Tables\IngestionBatchesTable;
use App\Models\IngestionBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngestionBatchResource extends Resource
{
    protected static ?string $model = IngestionBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return IngestionBatchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IngestionBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngestionBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngestionBatches::route('/'),
            'create' => CreateIngestionBatch::route('/create'),
            'view' => ViewIngestionBatch::route('/{record}'),
            'edit' => EditIngestionBatch::route('/{record}/edit'),
        ];
    }
}
