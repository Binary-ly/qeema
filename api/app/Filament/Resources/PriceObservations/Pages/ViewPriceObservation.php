<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Pages;

use App\Filament\Resources\PriceObservations\PriceObservationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPriceObservation extends ViewRecord
{
    protected static string $resource = PriceObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
