<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Reporters\Pages;

use App\Filament\Resources\Reporters\ReporterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReporters extends ListRecords
{
    protected static string $resource = ReporterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
