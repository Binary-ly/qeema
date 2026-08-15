<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ResolveSubmission;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Resolution;
use App\Models\Submission;
use App\Support\Text\TextNormalizer;
use Illuminate\Console\Command;

/**
 * Resolve queued submissions whose text the catalogue has since learned.
 *
 * **The gap this closes.** The review queue is a snapshot of what the matcher
 * could not resolve *at the time*. Growing the catalogue — adding an item,
 * promoting a corpus, a reviewer teaching it a phrase — changes what it can
 * resolve, and nothing went back for the rows already waiting. Measured
 * immediately after nine items were added to Libya: **26,937 queued submissions
 * carried text that had just become a catalogue variant**, every one of them
 * waiting for a human to answer a question the matcher could now answer itself.
 *
 * **Exact matches only, deliberately.** This resolves a submission only when its
 * normalised text is *identical* to a known variant — the same condition the
 * matcher's own short-circuit uses, which returns without consulting the model
 * at all. Nothing here is a judgement call, so nothing here can be wrong in a
 * way a human would have caught. Anything less certain stays in the queue where
 * it belongs.
 *
 * Not run automatically after an import. At 26,937 rows it is tens of minutes,
 * and a config import that silently blocks for half an hour is worse than one
 * that says what to run.
 */
final class RematchReviewQueueCommand extends Command
{
    /** Rows to load at once. */
    private const CHUNK = 500;

    protected $signature = 'qeema:review:rematch
                            {--country= : ISO code; defaults to every active country}
                            {--dry-run : Report what would resolve and change nothing}
                            {--limit=0 : Stop after this many resolutions}';

    protected $description = 'Resolve queued submissions whose text the catalogue has since learned';

    public function handle(ResolveSubmission $resolver, TextNormalizer $normalizer): int
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->when(
                $this->option('country') !== null && $this->option('country') !== '',
                fn ($query) => $query->where('code', strtoupper((string) $this->option('country'))),
            )
            ->get();

        if ($countries->isEmpty()) {
            $this->error('No matching active country.');

            return self::FAILURE;
        }

        foreach ($countries as $country) {
            $this->rematch($country, $resolver, $normalizer);
        }

        return self::SUCCESS;
    }

    private function rematch(Country $country, ResolveSubmission $resolver, TextNormalizer $normalizer): void
    {
        /** @var array<string, int> $known normalised variant text => canonical item id */
        $known = CanonicalItemVariant::query()
            ->join('canonical_items as i', 'i.id', '=', 'canonical_item_variants.canonical_item_id')
            ->where('i.country_id', $country->id)
            ->where('i.is_active', true)
            ->pluck('canonical_item_variants.canonical_item_id', 'canonical_item_variants.normalized_text')
            ->all();

        $items = CanonicalItem::query()->where('country_id', $country->id)->get()->keyBy('id');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $matched = 0;
        $resolved = 0;
        $unobservable = 0;

        $this->line(sprintf('%s: %d catalogue variant(s) to match against.', $country->code, count($known)));

        Submission::query()
            ->where('country_id', $country->id)
            ->awaitingReview()
            ->chunkById(self::CHUNK, function ($submissions) use (
                $known, $items, $resolver, $normalizer, $dryRun, $limit,
                &$matched, &$resolved, &$unobservable
            ): bool {
                foreach ($submissions as $submission) {
                    $itemId = $known[$normalizer->normalize($submission->raw_text)] ?? null;

                    if ($itemId === null || ! $items->has($itemId)) {
                        continue;
                    }

                    $matched++;

                    if ($dryRun) {
                        continue;
                    }

                    if ($this->resolve($submission, $items->get($itemId), $resolver)) {
                        $resolved++;
                    } else {
                        $unobservable++;
                    }

                    if ($limit > 0 && $resolved >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->info(sprintf(
            '  %d queued submission(s) are now catalogue text.%s',
            $matched,
            $dryRun ? ' Dry run — nothing changed.' : '',
        ));

        if (! $dryRun) {
            $this->line(sprintf('  %d resolved, %d left for review (price not expressible).', $resolved, $unobservable));

            if ($resolved > 0) {
                $this->warn('  New observations change published figures. Recompute with:');
                $this->line("    php artisan qeema:index --country={$country->code} --grace=0");
            }
        }
    }

    /**
     * Resolve one submission against the item its text now names.
     *
     * `METHOD_EXACT` because that is literally what happened: the normalised
     * text equals a known variant. Not `human` — nobody looked at this row — and
     * not `fused`, because no model ran.
     */
    private function resolve(Submission $submission, CanonicalItem $item, ResolveSubmission $resolver): bool
    {
        $observation = $submission->priceObservation ?? $resolver->createObservation($submission, $item);

        if ($observation === null) {
            // The text is resolved but the number is not usable — an unknown
            // unit, almost always. That is a different question and it still
            // needs a person, so the row stays in the queue.
            return false;
        }

        Resolution::query()->updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'canonical_item_id' => $item->id,
                'method' => Resolution::METHOD_EXACT,
                'confidence' => 1.0,
                'reviewed' => false,
                'notes' => 'Text became a catalogue variant after this submission was queued.',
            ],
        );

        $submission->forceFill(['status' => Submission::STATUS_RESOLVED])->save();

        return true;
    }
}
