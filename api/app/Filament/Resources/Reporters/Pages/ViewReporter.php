<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Reporters\Pages;

use App\Filament\Resources\Reporters\ReporterResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReporter extends ViewRecord
{
    protected static string $resource = ReporterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
