<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\BasketItems;

use App\Filament\Resources\BasketItems\Pages\CreateBasketItem;
use App\Filament\Resources\BasketItems\Pages\EditBasketItem;
use App\Filament\Resources\BasketItems\Pages\ListBasketItems;
use App\Filament\Resources\BasketItems\Pages\ViewBasketItem;
use App\Filament\Resources\BasketItems\Schemas\BasketItemForm;
use App\Filament\Resources\BasketItems\Schemas\BasketItemInfolist;
use App\Filament\Resources\BasketItems\Tables\BasketItemsTable;
use App\Models\BasketItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BasketItemResource extends Resource
{
    protected static ?string $model = BasketItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return BasketItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BasketItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BasketItemsTable::configure($table);
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
            'index' => ListBasketItems::route('/'),
            'create' => CreateBasketItem::route('/create'),
            'view' => ViewBasketItem::route('/{record}'),
            'edit' => EditBasketItem::route('/{record}/edit'),
        ];
    }
}
