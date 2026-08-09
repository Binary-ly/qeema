<?php

namespace App\Filament\Resources\Resolutions;

use App\Filament\Resources\Resolutions\Pages\CreateResolution;
use App\Filament\Resources\Resolutions\Pages\EditResolution;
use App\Filament\Resources\Resolutions\Pages\ListResolutions;
use App\Filament\Resources\Resolutions\Pages\ViewResolution;
use App\Filament\Resources\Resolutions\Schemas\ResolutionForm;
use App\Filament\Resources\Resolutions\Schemas\ResolutionInfolist;
use App\Filament\Resources\Resolutions\Tables\ResolutionsTable;
use App\Models\Resolution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ResolutionResource extends Resource
{
    protected static ?string $model = Resolution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ResolutionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResolutionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResolutionsTable::configure($table);
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
            'index' => ListResolutions::route('/'),
            'create' => CreateResolution::route('/create'),
            'view' => ViewResolution::route('/{record}'),
            'edit' => EditResolution::route('/{record}/edit'),
        ];
    }
}
