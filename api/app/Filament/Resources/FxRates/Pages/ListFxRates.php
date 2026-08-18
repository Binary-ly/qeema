<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\FxRates\Pages;

use App\Filament\Resources\FxRates\FxRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFxRates extends ListRecords
{
    protected static string $resource = FxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
