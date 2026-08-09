<?php

namespace App\Filament\Resources\Reporters;

use App\Filament\Resources\Reporters\Pages\CreateReporter;
use App\Filament\Resources\Reporters\Pages\EditReporter;
use App\Filament\Resources\Reporters\Pages\ListReporters;
use App\Filament\Resources\Reporters\Pages\ViewReporter;
use App\Filament\Resources\Reporters\Schemas\ReporterForm;
use App\Filament\Resources\Reporters\Schemas\ReporterInfolist;
use App\Filament\Resources\Reporters\Tables\ReportersTable;
use App\Models\Reporter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReporterResource extends Resource
{
    protected static ?string $model = Reporter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ReporterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReporterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportersTable::configure($table);
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
            'index' => ListReporters::route('/'),
            'create' => CreateReporter::route('/create'),
            'view' => ViewReporter::route('/{record}'),
            'edit' => EditReporter::route('/{record}/edit'),
        ];
    }
}
