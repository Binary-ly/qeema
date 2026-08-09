<?php

namespace App\Filament\Resources\AnomalyScores;

use App\Filament\Resources\AnomalyScores\Pages\CreateAnomalyScore;
use App\Filament\Resources\AnomalyScores\Pages\EditAnomalyScore;
use App\Filament\Resources\AnomalyScores\Pages\ListAnomalyScores;
use App\Filament\Resources\AnomalyScores\Pages\ViewAnomalyScore;
use App\Filament\Resources\AnomalyScores\Schemas\AnomalyScoreForm;
use App\Filament\Resources\AnomalyScores\Schemas\AnomalyScoreInfolist;
use App\Filament\Resources\AnomalyScores\Tables\AnomalyScoresTable;
use App\Models\AnomalyScore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AnomalyScoreResource extends Resource
{
    protected static ?string $model = AnomalyScore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return AnomalyScoreForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AnomalyScoreInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnomalyScoresTable::configure($table);
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
            'index' => ListAnomalyScores::route('/'),
            'create' => CreateAnomalyScore::route('/create'),
            'view' => ViewAnomalyScore::route('/{record}'),
            'edit' => EditAnomalyScore::route('/{record}/edit'),
        ];
    }
}
