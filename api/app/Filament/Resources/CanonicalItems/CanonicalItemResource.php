<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItems;

use App\Filament\Resources\CanonicalItems\Pages\CreateCanonicalItem;
use App\Filament\Resources\CanonicalItems\Pages\EditCanonicalItem;
use App\Filament\Resources\CanonicalItems\Pages\ListCanonicalItems;
use App\Filament\Resources\CanonicalItems\Pages\ViewCanonicalItem;
use App\Filament\Resources\CanonicalItems\Schemas\CanonicalItemForm;
use App\Filament\Resources\CanonicalItems\Schemas\CanonicalItemInfolist;
use App\Filament\Resources\CanonicalItems\Tables\CanonicalItemsTable;
use App\Models\CanonicalItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CanonicalItemResource extends Resource
{
    protected static ?string $model = CanonicalItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CanonicalItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CanonicalItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CanonicalItemsTable::configure($table);
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
            'index' => ListCanonicalItems::route('/'),
            'create' => CreateCanonicalItem::route('/create'),
            'view' => ViewCanonicalItem::route('/{record}'),
            'edit' => EditCanonicalItem::route('/{record}/edit'),
        ];
    }
}
