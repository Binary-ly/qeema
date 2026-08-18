<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Pages;

use App\Filament\Resources\PriceObservations\PriceObservationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceObservations extends ListRecords
{
    protected static string $resource = PriceObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
