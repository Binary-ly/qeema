<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\AnomalyScores\Pages;

use App\Filament\Resources\AnomalyScores\AnomalyScoreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnomalyScores extends ListRecords
{
    protected static string $resource = AnomalyScoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
