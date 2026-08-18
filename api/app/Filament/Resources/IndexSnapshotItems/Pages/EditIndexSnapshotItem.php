<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshotItems\Pages;

use App\Filament\Resources\IndexSnapshotItems\IndexSnapshotItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIndexSnapshotItem extends EditRecord
{
    protected static string $resource = IndexSnapshotItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
