<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Pages;

use App\Filament\Resources\PriceObservations\PriceObservationResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceObservation extends CreateRecord
{
    protected static string $resource = PriceObservationResource::class;
}
