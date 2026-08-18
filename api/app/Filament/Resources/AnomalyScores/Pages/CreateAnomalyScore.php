<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\AnomalyScores\Pages;

use App\Filament\Resources\AnomalyScores\AnomalyScoreResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnomalyScore extends CreateRecord
{
    protected static string $resource = AnomalyScoreResource::class;
}
