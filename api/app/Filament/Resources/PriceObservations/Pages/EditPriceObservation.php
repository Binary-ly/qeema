<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Pages;

use App\Filament\Resources\PriceObservations\PriceObservationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPriceObservation extends EditRecord
{
    protected static string $resource = PriceObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
