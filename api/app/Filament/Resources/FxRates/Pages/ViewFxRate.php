<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\FxRates\Pages;

use App\Filament\Resources\FxRates\FxRateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFxRate extends ViewRecord
{
    protected static string $resource = FxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
