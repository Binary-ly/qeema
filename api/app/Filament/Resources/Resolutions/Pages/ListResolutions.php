<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Resolutions\Pages;

use App\Filament\Resources\Resolutions\ResolutionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResolutions extends ListRecords
{
    protected static string $resource = ResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
