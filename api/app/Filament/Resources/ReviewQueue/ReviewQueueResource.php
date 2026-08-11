<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Filament\Resources\ReviewQueue;

use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Resources\ReviewQueue\Tables\ReviewQueueTable;
use App\Models\BasketItem;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The queue of submissions a machine declined to decide.
 *
 * The pipeline refuses to guess: an unconfident match, a matcher that was
 * unreachable, a price the detector distrusts — all of it lands here rather
 * than in the published index. That refusal is only honest if somebody can act
 * on what it produces, and until this screen existed nobody could. The actions
 * behind it were written and tested; the queue simply had no door.
 *
 * Deliberately list-only. Reviewing is repetitive triage, so everything needed
 * to decide is in the row — what was typed, what the matcher thought and how
 * strongly, what the detector thought, what the reporter's history is worth —
 * and the modals are confirmation rather than a second screen to read.
 */
final class ReviewQueueResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static ?string $slug = 'review-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Ingestion';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $modelLabel = 'submission awaiting review';

    protected static ?string $pluralModelLabel = 'submissions awaiting review';

    /**
     * Scoped to the queue itself, so nothing here can touch a submission that
     * has already been decided. Approving something twice is guarded further
     * down as well, but the narrowest query is the cheapest guard.
     *
     * Built from the model rather than from `parent::getEloquentQuery()`, which
     * returns a builder typed over `Model` and so loses every guarantee about
     * what is being queried. The parent's only other job is stripping tenancy
     * scopes, and this panel has no tenancy; if that ever changes, this
     * override has to change with it.
     *
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        return Submission::query()
            ->awaitingReview()
            ->with(['location', 'country', 'reporter', 'resolution.canonicalItem', 'latestAnomalyScore'])
            // The basket weight of the suggested item, so a reviewer with one
            // hour can spend it on the submissions that actually move a
            // published figure rather than on whatever arrived first.
            ->addSelect([
                'review_weight' => BasketItem::query()
                    ->select('basket_items.weight')
                    ->join('resolutions', 'resolutions.canonical_item_id', '=', 'basket_items.canonical_item_id')
                    ->whereColumn('resolutions.submission_id', 'submissions.id')
                    ->orderByDesc('basket_items.basket_id')
                    ->limit(1),
            ]);
    }

    /**
     * The backlog, on the navigation item.
     *
     * A review queue nobody can see the size of is a review queue that grows.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Submission::query()->awaitingReview()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return Submission::query()->awaitingReview()->count() > 100 ? 'danger' : 'warning';
    }

    public static function table(Table $table): Table
    {
        return ReviewQueueTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewQueue::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // Submissions arrive from reporters and importers. Inventing one here
        // would put a price in the index with no provenance behind it.
        return false;
    }
}
