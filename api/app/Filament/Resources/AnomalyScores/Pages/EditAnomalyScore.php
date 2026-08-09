<?php

namespace App\Filament\Resources\AnomalyScores\Pages;

use App\Filament\Resources\AnomalyScores\AnomalyScoreResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAnomalyScore extends EditRecord
{
    protected static string $resource = AnomalyScoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
