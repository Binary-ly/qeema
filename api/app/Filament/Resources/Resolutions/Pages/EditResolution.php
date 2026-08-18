<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Resolutions\Pages;

use App\Filament\Resources\Resolutions\ResolutionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditResolution extends EditRecord
{
    protected static string $resource = ResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
