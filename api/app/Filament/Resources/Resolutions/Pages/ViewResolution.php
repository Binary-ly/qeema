<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Resolutions\Pages;

use App\Filament\Resources\Resolutions\ResolutionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewResolution extends ViewRecord
{
    protected static string $resource = ResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
