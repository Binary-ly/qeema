<?php

namespace App\Filament\Resources\Reporters\Pages;

use App\Filament\Resources\Reporters\ReporterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditReporter extends EditRecord
{
    protected static string $resource = ReporterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
