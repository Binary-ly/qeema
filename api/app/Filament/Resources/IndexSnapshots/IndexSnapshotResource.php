<?php

namespace App\Filament\Resources\IndexSnapshots;

use App\Filament\Resources\IndexSnapshots\Pages\CreateIndexSnapshot;
use App\Filament\Resources\IndexSnapshots\Pages\EditIndexSnapshot;
use App\Filament\Resources\IndexSnapshots\Pages\ListIndexSnapshots;
use App\Filament\Resources\IndexSnapshots\Pages\ViewIndexSnapshot;
use App\Filament\Resources\IndexSnapshots\Schemas\IndexSnapshotForm;
use App\Filament\Resources\IndexSnapshots\Schemas\IndexSnapshotInfolist;
use App\Filament\Resources\IndexSnapshots\Tables\IndexSnapshotsTable;
use App\Models\IndexSnapshot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IndexSnapshotResource extends Resource
{
    protected static ?string $model = IndexSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return IndexSnapshotForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IndexSnapshotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IndexSnapshotsTable::configure($table);
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
            'index' => ListIndexSnapshots::route('/'),
            'create' => CreateIndexSnapshot::route('/create'),
            'view' => ViewIndexSnapshot::route('/{record}'),
            'edit' => EditIndexSnapshot::route('/{record}/edit'),
        ];
    }
}
