<?php

namespace App\Filament\Resources\Baskets;

use App\Filament\Resources\Baskets\Pages\CreateBasket;
use App\Filament\Resources\Baskets\Pages\EditBasket;
use App\Filament\Resources\Baskets\Pages\ListBaskets;
use App\Filament\Resources\Baskets\Pages\ViewBasket;
use App\Filament\Resources\Baskets\Schemas\BasketForm;
use App\Filament\Resources\Baskets\Schemas\BasketInfolist;
use App\Filament\Resources\Baskets\Tables\BasketsTable;
use App\Models\Basket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BasketResource extends Resource
{
    protected static ?string $model = Basket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return BasketForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BasketInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BasketsTable::configure($table);
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
            'index' => ListBaskets::route('/'),
            'create' => CreateBasket::route('/create'),
            'view' => ViewBasket::route('/{record}'),
            'edit' => EditBasket::route('/{record}/edit'),
        ];
    }
}
