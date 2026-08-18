<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshotItems\Pages;

use App\Filament\Resources\IndexSnapshotItems\IndexSnapshotItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIndexSnapshotItem extends ViewRecord
{
    protected static string $resource = IndexSnapshotItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
