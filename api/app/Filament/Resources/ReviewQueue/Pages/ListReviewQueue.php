<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Filament\Resources\ReviewQueue\Pages;

use App\Filament\Resources\ReviewQueue\ReviewQueueResource;
use Filament\Resources\Pages\ListRecords;

final class ListReviewQueue extends ListRecords
{
    protected static string $resource = ReviewQueueResource::class;

    public function getTitle(): string
    {
        return 'Review queue';
    }

    public function getSubheading(): string
    {
        return 'Submissions the pipeline declined to decide. Nothing here is in the published index.';
    }
}
