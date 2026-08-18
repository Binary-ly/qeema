<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItems\Pages;

use App\Filament\Resources\CanonicalItems\CanonicalItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCanonicalItem extends ViewRecord
{
    protected static string $resource = CanonicalItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
