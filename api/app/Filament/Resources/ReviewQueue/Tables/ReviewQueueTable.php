<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Filament\Resources\ReviewQueue\Tables;

use App\Actions\ApplyReviewDecision;
use App\Exceptions\SubmissionNotObservable;
use App\Models\AnomalyScore;
use App\Models\CanonicalItem;
use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything a reviewer needs to decide, in the row.
 *
 * A reviewer without context is a coin flip, and a coin flip that writes to the
 * published index is worse than no review at all. So the row carries the raw
 * text exactly as it was typed, the matcher's suggestion *and* its confidence,
 * the screening verdict, the reporter's standing, and the basket weight of what
 * is being decided — which is the difference between a decision that moves a
 * published figure and one that does not.
 */
final class ReviewQueueTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first. A queue worked newest-first quietly abandons its
            // tail, and the tail is where the oldest unpublished prices are.
            ->defaultSort('observed_at', 'asc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkApproveAction(),
                ]),
            ])
            ->emptyStateHeading('Nothing is waiting for review')
            ->emptyStateDescription('Every submission the pipeline could not decide has been decided.');
    }

    /**
     * @return list<TextColumn>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('observed_at')
                ->label('Seen')
                ->dateTime('Y-m-d H:i')
                ->sortable()
                ->description(fn (Submission $record): string => $record->wasSubmittedOffline() ? 'queued offline' : ''),

            TextColumn::make('location.name')
                ->label('Location')
                ->searchable()
                ->sortable(),

            TextColumn::make('raw_text')
                ->label('As reported')
                ->wrap()
                ->searchable()
                ->weight('medium'),

            TextColumn::make('raw_price')
                ->label('Price')
                ->numeric(decimalPlaces: 2)
                ->description(fn (Submission $record): string => trim(
                    $record->currency_code.' per '.($record->raw_quantity ?? 1).' '.($record->raw_unit ?? '')
                ))
                ->sortable(),

            TextColumn::make('resolution.canonicalItem.name_en')
                ->label('Matcher suggests')
                ->placeholder('no suggestion')
                ->description(fn (Submission $record): ?string => $record->resolution?->canonicalItem?->code),

            TextColumn::make('resolution.confidence')
                ->label('Confidence')
                ->badge()
                ->placeholder('—')
                ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                ->color(fn ($state): string => match (true) {
                    $state === null => 'gray',
                    (float) $state >= 0.8 => 'warning',
                    default => 'danger',
                }),

            TextColumn::make('latestAnomalyScore.verdict')
                ->label('Screening')
                ->badge()
                ->placeholder('unscreened')
                ->color(fn (?string $state): string => match ($state) {
                    AnomalyScore::VERDICT_REJECTED => 'danger',
                    AnomalyScore::VERDICT_SUSPECT => 'warning',
                    AnomalyScore::VERDICT_CLEAN => 'success',
                    default => 'gray',
                })
                ->description(fn (Submission $record): ?string => self::anomalyReasons($record)),

            TextColumn::make('review_weight')
                ->label('Basket weight')
                ->placeholder('—')
                ->formatStateUsing(fn ($state): string => number_format((float) $state, 3))
                // The impact sort. Sorting by this is how an hour of review
                // buys the most correction to the published index.
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('review_weight', $direction)),

            TextColumn::make('reporter.reputation')
                ->label('Reporter')
                ->numeric(decimalPlaces: 2)
                ->placeholder('—')
                ->description(fn (Submission $record): ?string => $record->reporter === null
                    ? null
                    : "{$record->reporter->submissions_accepted}/{$record->reporter->submissions_total} accepted"),

            TextColumn::make('resolution.notes')
                ->label('Why it is here')
                ->wrap()
                ->limit(120)
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return list<SelectFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('country')
                ->relationship('country', 'name')
                ->searchable(),

            SelectFilter::make('location')
                ->relationship('location', 'name')
                ->searchable(),

            // Structural rather than string-matched against the notes: the
            // three reasons a submission lands here are genuinely different
            // kinds of work, and a reviewer usually wants one kind at a time.
            SelectFilter::make('why')
                ->label('Reason')
                ->options([
                    'low_confidence' => 'Matcher unsure',
                    'no_suggestion' => 'No suggestion at all',
                    'flagged' => 'Price flagged by screening',
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'low_confidence' => $query->whereHas(
                        'resolution',
                        fn (Builder $q): Builder => $q->whereNotNull('canonical_item_id')->whereNotNull('confidence'),
                    ),
                    'no_suggestion' => $query->where(
                        fn (Builder $q): Builder => $q
                            ->whereDoesntHave('resolution')
                            ->orWhereHas('resolution', fn (Builder $r): Builder => $r->whereNull('canonical_item_id')),
                    ),
                    'flagged' => $query->whereHas(
                        'anomalyScores',
                        fn (Builder $q): Builder => $q->whereIn('verdict', [
                            AnomalyScore::VERDICT_SUSPECT,
                            AnomalyScore::VERDICT_REJECTED,
                        ]),
                    ),
                    default => $query,
                }),
        ];
    }

    /**
     * Confirm what a price is for, and publish it.
     */
    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Confirm what this price is for')
            ->modalSubmitActionLabel('Approve and publish')
            // Prefilled with the matcher's suggestion, because the common case
            // by far is that it was right and merely unsure.
            ->fillForm(fn (Submission $record): array => [
                'canonical_item_id' => $record->resolution?->canonical_item_id,
            ])
            ->schema([
                Select::make('canonical_item_id')
                    ->label('Item')
                    ->options(fn (Submission $record): array => CanonicalItem::query()
                        ->where('country_id', $record->country_id)
                        ->orderBy('name_en')
                        ->pluck('name_en', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Confirming this also teaches the matcher the phrase, so it resolves automatically next time.'),
            ])
            ->action(function (Submission $record, array $data): void {
                $item = CanonicalItem::query()->find($data['canonical_item_id']);

                if ($item === null) {
                    Notification::make()->title('That item no longer exists')->danger()->send();

                    return;
                }

                try {
                    app(ApplyReviewDecision::class)->approve($record, $item, auth()->id());
                } catch (SubmissionNotObservable $e) {
                    // Loud, and with the reason. The alternative — marking it
                    // resolved anyway — tells the reviewer they published a
                    // price that never reached the index.
                    Notification::make()
                        ->title('This price cannot be published')
                        ->body($e->getMessage().' Reject it, or configure the unit and try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Approved')
                    ->body("Published as {$item->name_en}. The index will pick it up on the next recompute.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Reject a submission that cannot be used at all.
     */
    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject this submission')
            ->modalSubmitActionLabel('Reject')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->minLength(3)
                    ->rows(3)
                    // Required rather than optional: a rejection with no reason
                    // is unauditable, and it counts against a real person's
                    // standing.
                    ->helperText('Recorded against the submission, and counted against the reporter.'),
            ])
            ->action(function (Submission $record, array $data): void {
                app(ApplyReviewDecision::class)->reject($record, (string) $data['reason'], auth()->id());

                Notification::make()->title('Rejected')->success()->send();
            });
    }

    /**
     * The dominant case, in bulk: the matcher was right and merely unsure.
     *
     * Without this the queue is not drainable by one person, which is the same
     * as not being drainable.
     */
    private static function bulkApproveAction(): BulkAction
    {
        return BulkAction::make('approve_suggested')
            ->label('Approve the suggested match')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve each of these as the matcher suggested')
            ->modalDescription('Anything without a suggestion is left alone. You will be told how many.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $approved = 0;
                $skipped = 0;
                $unusable = 0;

                foreach ($records as $record) {
                    $item = $record->resolution?->canonical_item_id === null
                        ? null
                        : CanonicalItem::query()->find($record->resolution->canonical_item_id);

                    if ($item === null) {
                        $skipped++;

                        continue;
                    }

                    try {
                        app(ApplyReviewDecision::class)->approve($record, $item, auth()->id());
                        $approved++;
                    } catch (SubmissionNotObservable) {
                        $unusable++;
                    }
                }

                // Every number stated. A bulk action that silently drops part
                // of its input reads as "all done" when it was not.
                Notification::make()
                    ->title("Approved {$approved}")
                    ->body(trim(sprintf(
                        '%s%s',
                        $skipped > 0 ? "{$skipped} had no suggestion and were left for you. " : '',
                        $unusable > 0 ? "{$unusable} could not be normalised to a price per base unit." : '',
                    )) ?: 'Every selected submission was published.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The detector's reasons, flattened for a table cell.
     */
    private static function anomalyReasons(Submission $record): ?string
    {
        $reasons = $record->latestAnomalyScore?->reasons;

        if (! is_array($reasons) || $reasons === []) {
            return null;
        }

        $codes = array_map(
            static fn ($reason): string => is_array($reason) ? (string) ($reason['code'] ?? '') : (string) $reason,
            $reasons,
        );

        return implode(', ', array_filter($codes)) ?: null;
    }
}
