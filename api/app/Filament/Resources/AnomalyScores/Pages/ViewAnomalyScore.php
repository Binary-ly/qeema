<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\AnomalyScores\Pages;

use App\Filament\Resources\AnomalyScores\AnomalyScoreResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAnomalyScore extends ViewRecord
{
    protected static string $resource = AnomalyScoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
