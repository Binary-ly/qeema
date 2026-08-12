<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds reporters whose prices are systematically off, and tells a human.
 *
 * The detector this calls has existed since Phase 6, is covered by tests, and
 * had **no caller at all** — so the platform's only defence against coordinated
 * price manipulation was a module nothing ran. The synthetic generator has been
 * seeding a bad-actor cluster into the demo data the whole time, and nothing has
 * ever looked for it.
 *
 * **The reference must exclude the reporter being judged.** A cluster large
 * enough to shift a local median otherwise hides inside it: measured against a
 * median it helped set, a coordinated group looks unremarkable. That exclusion
 * is the difference between catching the behaviour and confirming it.
 *
 * **Nothing is blocked automatically.** The detector produces a reason to look,
 * not a verdict. Suspending somebody's contributions on a statistical signal is
 * a judgement about a real person doing real work in a difficult place, and it
 * belongs to an operator who can weigh it — the same reason an unconfident match
 * goes to a review queue instead of being guessed at.
 */
final class DetectReporterBiasCommand extends Command
{
    protected $signature = 'qeema:reporters:bias
                            {--country= : ISO code; defaults to every active country}
                            {--days=60 : How far back to judge a reporter on}
                            {--limit=20000 : Maximum observations examined per country}';

    protected $description = 'Flag reporters whose prices sit consistently away from their neighbours';

    public function handle(MlClientInterface $ml): int
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->when(
                $this->option('country'),
                fn ($query) => $query->where('code', strtoupper((string) $this->option('country'))),
            )
            ->get();

        foreach ($countries as $country) {
            $this->examine($country, $ml);
        }

        return self::SUCCESS;
    }

    private function examine(Country $country, MlClientInterface $ml): void
    {
        $records = $this->records($country);

        if ($records === []) {
            $this->line("{$country->code}: not enough overlapping history to judge anyone on.");

            return;
        }

        $results = $ml->detectReporterBias($records);

        if ($results === null) {
            $this->warn("{$country->code}: the detector had no opinion.");

            return;
        }

        $flagged = 0;

        foreach ($results as $result) {
            $reporter = Reporter::query()->find($result['reporter_id'] ?? null);

            if ($reporter === null) {
                continue;
            }

            $isFlagged = (bool) ($result['is_suspicious'] ?? false);

            $reporter->recordBias(
                isset($result['modified_z']) ? (float) $result['modified_z'] : null,
                $isFlagged,
                $isFlagged ? (string) ($result['reason'] ?? '') : null,
            );

            if ($isFlagged) {
                $flagged++;
            }
        }

        $waiting = Reporter::query()->where('country_id', $country->id)->awaitingBiasReview()->count();

        $this->line(sprintf(
            '%s: judged %d reporter(s), %d flagged, %d awaiting a human.',
            $country->code,
            count($results),
            $flagged,
            $waiting,
        ));

        if ($flagged > 0) {
            Log::warning('Reporters flagged for possible price manipulation', [
                'country' => $country->code,
                'flagged' => $flagged,
                'awaiting_review' => $waiting,
            ]);
        }
    }

    /**
     * One record per observation: the price, and what everyone else said.
     *
     * The reference is the median price for the same item in the same place,
     * over observations from **other reporters**. Computed here rather than in
     * SQL because the exclusion is per-reporter rather than per-row, and a
     * query expressing that is harder to read than it is to check.
     *
     * @return list<array{reporter_id: string, price: float, reference: float}>
     */
    private function records(Country $country): array
    {
        $since = CarbonImmutable::now($country->timezone)
            ->subDays((int) $this->option('days'))
            ->toDateString();

        $observations = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('observed_on', '>=', $since)
            ->whereNotNull('reporter_id')
            ->valid()
            ->orderBy('observed_on')
            ->limit((int) $this->option('limit'))
            ->get(['reporter_id', 'location_id', 'canonical_item_id', 'normalized_price_per_base_unit']);

        $cells = [];

        foreach ($observations as $observation) {
            $key = $observation->location_id.':'.$observation->canonical_item_id;
            $cells[$key][] = [
                'reporter_id' => (string) $observation->reporter_id,
                'price' => (float) $observation->normalized_price_per_base_unit,
            ];
        }

        $records = [];

        foreach ($cells as $rows) {
            foreach ($rows as $row) {
                $others = array_values(array_map(
                    static fn (array $other): float => $other['price'],
                    array_filter($rows, static fn (array $other): bool => $other['reporter_id'] !== $row['reporter_id']),
                ));

                if ($others === []) {
                    // Nobody else priced this item here, so there is nothing to
                    // be out of step with. Judging a reporter against their own
                    // price would flag whoever is simply alone in a place.
                    continue;
                }

                $records[] = [
                    'reporter_id' => $row['reporter_id'],
                    'price' => $row['price'],
                    'reference' => $this->median($others),
                ];
            }
        }

        return $records;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
