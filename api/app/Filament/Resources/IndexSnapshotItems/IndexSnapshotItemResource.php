<?php

namespace App\Filament\Resources\IndexSnapshotItems;

use App\Filament\Resources\IndexSnapshotItems\Pages\CreateIndexSnapshotItem;
use App\Filament\Resources\IndexSnapshotItems\Pages\EditIndexSnapshotItem;
use App\Filament\Resources\IndexSnapshotItems\Pages\ListIndexSnapshotItems;
use App\Filament\Resources\IndexSnapshotItems\Pages\ViewIndexSnapshotItem;
use App\Filament\Resources\IndexSnapshotItems\Schemas\IndexSnapshotItemForm;
use App\Filament\Resources\IndexSnapshotItems\Schemas\IndexSnapshotItemInfolist;
use App\Filament\Resources\IndexSnapshotItems\Tables\IndexSnapshotItemsTable;
use App\Models\IndexSnapshotItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IndexSnapshotItemResource extends Resource
{
    protected static ?string $model = IndexSnapshotItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return IndexSnapshotItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IndexSnapshotItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IndexSnapshotItemsTable::configure($table);
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
            'index' => ListIndexSnapshotItems::route('/'),
            'create' => CreateIndexSnapshotItem::route('/create'),
            'view' => ViewIndexSnapshotItem::route('/{record}'),
            'edit' => EditIndexSnapshotItem::route('/{record}/edit'),
        ];
    }
}
