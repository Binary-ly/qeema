<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Synthetic;

use App\Models\AnomalyScore;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Generates a plausible multi-month price history, with ground-truth labels.
 *
 * This is load-bearing rather than decorative. The platform has to be
 * demonstrable before a single real reporter exists, and Phases 5, 6 and 8 have
 * no way to report an honest accuracy figure without labelled data to score
 * against. So this class produces two things at once: a realistic-looking
 * public dataset, and a private answer key.
 *
 * The answer key lives in the `qeema_eval` schema and is never joined to by
 * anything the API can reach. That separation is physical, not a convention:
 * a label leaking into a published response would be a credibility failure the
 * project could not recover from.
 *
 * Everything is driven by a seed, so the same configuration produces identical
 * data on any machine and evaluation numbers are comparable across runs.
 */
final class SyntheticDataGenerator
{
    private const INSERT_CHUNK = 500;

    /** Probability a given (location, item, day) cell is observed at all. */
    private const OBSERVATION_RATE = 0.30;

    /** Share of (location, item) pairs that are never observed anywhere in the series. */
    private const BLIND_SPOT_RATE = 0.07;

    /** Share of weeks in which a location reports nothing at all. */
    private const BLACKOUT_WEEK_RATE = 0.08;

    /** Share of submissions that are honest mistakes. */
    private const ERROR_RATE = 0.05;

    /** Share of submissions that are deliberate manipulation. */
    private const MANIPULATION_RATE = 0.02;

    /** Share of submissions the matcher would route to human review. */
    private const REVIEW_RATE = 0.05;

    private Randomizer $randomizer;

    private RawTextGenerator $textGenerator;

    public function __construct(private readonly int $seed = 20260101)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
        $this->textGenerator = new RawTextGenerator($this->randomizer);
    }

    /**
     * @param  array<string, mixed>  $demoConfig  the `demo:` block of a country file
     */
    public function generate(Country $country, array $demoConfig, ?callable $progress = null): GenerationSummary
    {
        // Only when a caller passes one. Defaulting to whatever corpus file
        // happens to exist would silently change what `qeema:bootstrap`
        // produces, and with it every matching figure measured against the
        // shipped demo. The corpus is a harder test that is asked for, not one
        // that arrives by being on disk.
        if (($demoConfig['corpus'] ?? null) instanceof ReporterCorpus) {
            $this->textGenerator = new RawTextGenerator($this->randomizer, $demoConfig['corpus']);
        }

        $months = (int) ($demoConfig['months'] ?? 6);
        $days = (int) round($months * 30.44);

        // Scale knobs. All three default to the values the shipped demo has
        // always used, so a country file that sets none of them generates
        // exactly what it did before.
        $days = max(1, (int) ($demoConfig['days'] ?? $days));
        $observationRate = (float) ($demoConfig['observation_rate'] ?? self::OBSERVATION_RATE);

        // How many different reporters may report the same item, in the same
        // place, on the same day. One is thin: a real market has several people
        // watching the same shelf, and it is that overlap the estimator, the
        // anomaly screen and the reporter-bias detector all work from.
        $reportsPerCell = max(1, (int) ($demoConfig['reports_per_cell'] ?? 1));

        // Submissions that match nothing in the catalogue. Without them a
        // dataset measures recall only: every row is a labelled positive, so the
        // failure that actually matters — matching something confidently that
        // should have been refused — cannot be observed at all.
        $distractors = ($demoConfig['corpus'] ?? null) instanceof ReporterCorpus
            ? $demoConfig['corpus']->distractors()
            : [];
        $distractorRate = (float) ($demoConfig['distractor_rate'] ?? 0.0);
        $startDate = CarbonImmutable::today()->subDays($days - 1);

        $locations = $country->locations()->where('is_active', true)->get();
        $basket = $country->basketOn($startDate) ?? $country->baskets()->orderByDesc('version')->firstOrFail();
        $basketItems = $basket->items()->with('canonicalItem')->get();

        // Every catalogued item, not only the ones in the current basket.
        //
        // A country file may catalogue an item deliberately ahead of putting it
        // in a basket — `ly.yaml` does exactly that, and says so — because a
        // basket revision can only be chain-linked if the new items were already
        // being reported on the link date. Generating prices for basket items
        // alone made that impossible: the reserved items had zero observations,
        // so a v2 basket containing them could never be priced in full and the
        // linker would correctly refuse to anchor it.
        //
        // The index sums basket items only, so this changes no published figure.
        // It changes what a revision can be demonstrated against.
        /** @var array<string, CanonicalItem> $itemsByCode */
        $itemsByCode = [];
        /** @var array<string, string> $categories */
        $categories = [];

        foreach ($country->canonicalItems()->where('is_active', true)->get() as $item) {
            $itemsByCode[$item->code] = $item;
            $categories[$item->code] = $item->category;
        }

        $units = Unit::query()->where('country_id', $country->id)->get()->keyBy('code');

        $process = new PriceProcess(
            referencePrices: $this->perBaseUnitReferences(
                $this->floatMap($demoConfig['reference_prices'] ?? []),
                $itemsByCode,
                $units->pluck('factor_to_base', 'code')->map(fn ($f): float => (float) $f)->all(),
            ),
            regionalPremium: $this->floatMap($demoConfig['regional_premium'] ?? []),
            itemCategories: $categories,
            monthlyInflation: (float) ($demoConfig['monthly_inflation'] ?? 0.02),
            seed: $this->seed,
        );

        $locationSlugs = $locations->pluck('slug')->all();
        $itemCodes = array_keys($itemsByCode);
        $process->prepare($locationSlugs, $itemCodes, $days);

        $fxPath = $process->fxPath($days);
        $this->writeFxRates($country, $startDate, $fxPath, $demoConfig);

        $reporters = $this->createReporters($country, $locations, (int) ($demoConfig['reporters_per_location'] ?? 4));
        $source = Source::query()
            ->where('country_id', $country->id)
            ->where('type', Source::TYPE_REPORTER)
            ->firstOrFail();

        $blindSpots = $this->chooseBlindSpots($locationSlugs, $itemCodes);
        $blackouts = $this->chooseBlackoutWeeks($locationSlugs, (int) ceil($days / 7));
        $manipulators = $this->chooseManipulators($reporters);

        return $this->emit(
            country: $country,
            locations: $locations,
            itemsByCode: $itemsByCode,
            basketItems: $basketItems,
            units: $units,
            reporters: $reporters,
            source: $source,
            process: $process,
            fxPath: $fxPath,
            startDate: $startDate,
            days: $days,
            blindSpots: $blindSpots,
            blackouts: $blackouts,
            manipulators: $manipulators,
            observationRate: $observationRate,
            reportsPerCell: $reportsPerCell,
            distractors: $distractors,
            distractorRate: $distractorRate,
            progress: $progress,
        );
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  array<string, CanonicalItem>  $itemsByCode
     * @param  Collection<int, BasketItem>  $basketItems
     * @param  Collection<string, Unit>  $units
     * @param  Collection<int, Reporter>  $reporters
     * @param  list<float>  $fxPath
     * @param  list<string>  $distractors
     * @param  array<string, true>  $blindSpots
     * @param  array<string, true>  $blackouts
     * @param  array<int, true>  $manipulators
     */
    private function emit(
        Country $country,
        $locations,
        array $itemsByCode,
        $basketItems,
        $units,
        $reporters,
        Source $source,
        PriceProcess $process,
        array $fxPath,
        CarbonImmutable $startDate,
        int $days,
        float $observationRate,
        int $reportsPerCell,
        array $distractors,
        float $distractorRate,
        array $blindSpots,
        array $blackouts,
        array $manipulators,
        ?callable $progress,
    ): GenerationSummary {
        $reportersByLocation = $reporters->groupBy('location_id');
        $quantityByItem = $basketItems->keyBy(fn ($e) => $e->canonicalItem->code);

        $submissions = [];
        $observations = [];
        $resolutions = [];
        $anomalies = [];
        $groundTruthPrices = [];
        $groundTruthSubmissions = [];

        // Running counters rather than counting the buffers at the end: flush()
        // empties them, so a count taken afterwards reports zero.
        $counts = [
            'submissions' => 0, 'observations' => 0, 'erroneous' => 0,
            'manipulated' => 0, 'review' => 0, 'gt_prices' => 0,
        ];

        for ($day = 0; $day < $days; $day++) {
            $date = $startDate->addDays($day);
            $week = intdiv($day, 7);
            $fx = $fxPath[$day];
            $fxLagged = PriceProcess::lagged($fxPath, $day);

            foreach ($locations as $location) {
                $blackedOut = isset($blackouts[$location->slug.'|'.$week]);

                foreach ($itemsByCode as $code => $item) {
                    $truePrice = $process->truePrice($location->slug, $code, $day, $fx, $fxLagged);

                    // Ground truth is recorded for EVERY cell, including days
                    // with no observation. That is exactly what makes
                    // imputation error measurable rather than assumed.
                    $groundTruthPrices[] = [
                        'location_id' => $location->id,
                        'canonical_item_id' => $item->id,
                        'price_date' => $date->toDateString(),
                        'true_price_per_base_unit' => $truePrice,
                    ];
                    $counts['gt_prices']++;

                    if ($blackedOut || isset($blindSpots[$location->slug.'|'.$code])) {
                        continue;
                    }

                    if ($process->randomizer()->getFloat(0.0, 1.0) > $observationRate) {
                        continue;
                    }

                    $locationReporters = $reportersByLocation[$location->id] ?? collect();
                    if ($locationReporters->isEmpty()) {
                        continue;
                    }

                    // Several reporters may cover the same cell. Each gets its
                    // own draw from the observation process, so they disagree
                    // the way real reporters do rather than echoing one number.
                    $reportsHere = min($reportsPerCell, $locationReporters->count());

                    for ($report = 0; $report < $reportsHere; $report++) {
                        $reporter = $locationReporters[$this->randomizer->getInt(0, $locationReporters->count() - 1)];

                        $record = $this->buildSubmission(
                            country: $country,
                            location: $location,
                            item: $item,
                            reporter: $reporter,
                            source: $source,
                            // A catalogued item outside the basket has no basket
                            // entry to take a unit or quantity from, so it falls
                            // back to the catalogue's own defaults.
                            unit: $units[$quantityByItem[$code]->unit_code ?? $item->default_unit_code] ?? $units->first(),
                            basketQuantity: (float) ($quantityByItem[$code]->quantity ?? $item->default_quantity),
                            truePrice: $truePrice,
                            observedPrice: $process->observedPrice($truePrice),
                            date: $date,
                            fxRate: $fx,
                            isManipulator: isset($manipulators[$reporter->id]),
                        );

                        $submissions[] = $record['submission'];
                        $groundTruthSubmissions[] = $record['ground_truth'];
                        $counts['submissions']++;

                        if ($record['ground_truth']['is_erroneous']) {
                            $counts['erroneous']++;
                        }
                        if ($record['ground_truth']['is_manipulated']) {
                            $counts['manipulated']++;
                        }

                        if ($record['resolution'] !== null) {
                            $resolutions[] = $record['resolution'];
                        }
                        if ($record['observation'] !== null) {
                            $observations[] = $record['observation'];
                            $counts['observations']++;
                        } else {
                            $counts['review']++;
                        }
                        if ($record['anomaly'] !== null) {
                            $anomalies[] = $record['anomaly'];
                        }
                    }
                }

                // What arrives that the catalogue has no answer for: another
                // product entirely, a fragment too vague to resolve, a greeting
                // typed into the wrong box. It lands in the review queue with
                // ground truth recording that there IS no right answer, which is
                // what lets precision be measured rather than only recall.
                // Expressed as an expected COUNT per location-day rather than a
                // probability, because a probability caps this at one and a real
                // public inbox carries several percent junk — a share a coin
                // flip per location-day cannot reach.
                $unmatchable = (int) floor($distractorRate);

                if ($this->randomizer->getFloat(0.0, 1.0) < ($distractorRate - $unmatchable)) {
                    $unmatchable++;
                }

                for ($n = 0; $distractors !== [] && $n < $unmatchable; $n++) {
                    $pool = $reportersByLocation[$location->id] ?? collect();

                    if ($pool->isNotEmpty()) {
                        $record = $this->buildDistractor(
                            country: $country,
                            location: $location,
                            reporter: $pool[$this->randomizer->getInt(0, $pool->count() - 1)],
                            source: $source,
                            text: $distractors[$this->randomizer->getInt(0, count($distractors) - 1)],
                            date: $date,
                        );

                        $submissions[] = $record['submission'];
                        $groundTruthSubmissions[] = $record['ground_truth'];
                        $counts['submissions']++;
                        $counts['review']++;
                    }
                }
            }

            // Flush per day so memory stays flat regardless of series length.
            if (count($submissions) >= self::INSERT_CHUNK) {
                $this->flush($submissions, $resolutions, $observations, $anomalies, $groundTruthSubmissions, $groundTruthPrices);
            }

            if ($progress !== null && $day % 15 === 0) {
                $progress($day, $days);
            }
        }

        $this->flush($submissions, $resolutions, $observations, $anomalies, $groundTruthSubmissions, $groundTruthPrices);

        $this->updateReporterStats();

        return new GenerationSummary(
            days: $days,
            locations: $locations->count(),
            items: count($itemsByCode),
            submissions: $counts['submissions'],
            observations: $counts['observations'],
            erroneous: $counts['erroneous'],
            manipulated: $counts['manipulated'],
            queuedForReview: $counts['review'],
            groundTruthCells: $counts['gt_prices'],
        );
    }

    /**
     * A submission with no correct answer.
     *
     * Deliberately carries no resolution at all rather than a low-confidence
     * guess: nothing matched, which is a different state from "matched badly",
     * and the review queue is where it belongs. Its ground-truth row has a null
     * item, which is the record that no catalogue entry would have been right.
     *
     * @return array{submission: array<string, mixed>, ground_truth: array<string, mixed>}
     */
    private function buildDistractor(
        Country $country,
        Location $location,
        Reporter $reporter,
        Source $source,
        string $text,
        CarbonImmutable $date,
    ): array {
        $id = Str::uuid()->toString();
        $collectedAt = $date->setTime($this->randomizer->getInt(7, 20), $this->randomizer->getInt(0, 59));

        return [
            'submission' => [
                'id' => $id,
                'country_id' => $country->id,
                'location_id' => $location->id,
                'reporter_id' => $reporter->id,
                'source_id' => $source->id,
                'ingestion_batch_id' => null,
                'raw_text' => $text,
                // Somebody reporting a price for something uncatalogued still
                // types a price, so the row looks like every other submission
                // until you try to resolve it.
                'raw_price' => round($this->randomizer->getFloat(0.5, 400.0), 2),
                'currency_code' => $country->currency_code,
                'raw_unit' => null,
                'raw_quantity' => 1,
                'photo_path' => null,
                'observed_at' => $collectedAt,
                'collected_at' => $collectedAt,
                'ingested_at' => $collectedAt->addSeconds($this->randomizer->getInt(1, 90)),
                'device_metadata' => json_encode([
                    'app_version' => '0.1.0',
                    'platform' => $this->randomizer->getInt(0, 1) === 0 ? 'android' : 'ios',
                    'queued_offline' => false,
                    'synthetic' => true,
                ]),
                'client_idempotency_key' => Str::uuid()->toString(),
                'status' => Submission::STATUS_NEEDS_REVIEW,
                'created_at' => $collectedAt,
                'updated_at' => $collectedAt,
            ],
            'ground_truth' => [
                'submission_id' => $id,
                'true_canonical_item_id' => null,
                'true_price_per_base_unit' => null,
                'is_erroneous' => false,
                'is_manipulated' => false,
                'error_type' => null,
            ],
        ];
    }

    /**
     * Build one submission and everything derived from it.
     *
     * @return array{submission: array<string, mixed>, resolution: array<string, mixed>|null, observation: array<string, mixed>|null, anomaly: array<string, mixed>|null, ground_truth: array<string, mixed>}
     */
    private function buildSubmission(
        Country $country,
        Location $location,
        CanonicalItem $item,
        Reporter $reporter,
        Source $source,
        Unit $unit,
        float $basketQuantity,
        float $truePrice,
        float $observedPrice,
        CarbonImmutable $date,
        float $fxRate,
        bool $isManipulator,
    ): array {
        $id = Str::uuid()->toString();
        $roll = $this->randomizer->getFloat(0.0, 1.0);

        $errorType = null;
        $isErroneous = false;
        $isManipulated = false;
        $reportedPricePerBaseUnit = $observedPrice;

        if ($isManipulator && $roll < self::MANIPULATION_RATE * 6) {
            // Coordinated suppression: a cluster reporting systematically low,
            // each individual figure plausible, the pattern only visible across
            // the cluster. This is the case a per-observation outlier test
            // cannot catch and the reputation layer has to.
            $isManipulated = true;
            $reportedPricePerBaseUnit = round($observedPrice * $this->randomizer->getFloat(0.62, 0.78), 4);
        } elseif ($roll < self::ERROR_RATE) {
            $isErroneous = true;
            [$reportedPricePerBaseUnit, $errorType] = $this->applyError($observedPrice, $fxRate);
        }

        // What the reporter actually types is a price for a quantity in a unit,
        // not a normalised per-base-unit figure. Converting back here is what
        // makes unit-confusion errors realistic rather than synthetic-looking.
        $quantity = max(0.0001, $basketQuantity);
        $rawPrice = round($reportedPricePerBaseUnit * $quantity * $unit->factor_to_base, 3);

        $variants = $item->relationLoaded('variants')
            ? $item->variants->pluck('text')->all()
            : [];

        $collectedAt = $date->setTime($this->randomizer->getInt(7, 20), $this->randomizer->getInt(0, 59));
        $queuedOffline = $this->randomizer->getFloat(0.0, 1.0) < 0.18;
        $ingestedAt = $queuedOffline
            ? $collectedAt->addHours($this->randomizer->getInt(2, 96))
            : $collectedAt->addSeconds($this->randomizer->getInt(1, 90));

        $submission = [
            'id' => $id,
            'country_id' => $country->id,
            'location_id' => $location->id,
            'reporter_id' => $reporter->id,
            'source_id' => $source->id,
            'ingestion_batch_id' => null,
            'raw_text' => $this->textGenerator->generate(
                $item->name_local ?? $item->name_en,
                $variants,
                $item->code,
            ),
            'raw_price' => $rawPrice,
            'currency_code' => $country->currency_code,
            'raw_unit' => $this->textGenerator->unitText($unit->code),
            'raw_quantity' => $quantity,
            'photo_path' => null,
            'observed_at' => $collectedAt,
            'collected_at' => $collectedAt,
            'ingested_at' => $ingestedAt,
            'device_metadata' => json_encode([
                'app_version' => '0.1.0',
                'platform' => $this->randomizer->getInt(0, 1) === 0 ? 'android' : 'ios',
                'queued_offline' => $queuedOffline,
                'synthetic' => true,
            ]),
            'client_idempotency_key' => Str::uuid()->toString(),
            'status' => Submission::STATUS_RESOLVED,
            'created_at' => $ingestedAt,
            'updated_at' => $ingestedAt,
        ];

        // A slice goes to the review queue so the demo has something in it and
        // the Filament review screen is not an empty page.
        $needsReview = $this->randomizer->getFloat(0.0, 1.0) < self::REVIEW_RATE;

        $groundTruth = [
            'submission_id' => $id,
            'true_canonical_item_id' => $item->id,
            'true_price_per_base_unit' => $truePrice,
            'is_erroneous' => $isErroneous,
            'is_manipulated' => $isManipulated,
            'error_type' => $errorType,
        ];

        if ($needsReview) {
            $submission['status'] = Submission::STATUS_NEEDS_REVIEW;

            return [
                'submission' => $submission,
                'resolution' => [
                    'submission_id' => $id,
                    'canonical_item_id' => $item->id,
                    'method' => Resolution::METHOD_FUSED,
                    'confidence' => round($this->randomizer->getFloat(0.30, 0.54), 4),
                    'candidates' => null,
                    'reviewed' => false,
                    'model_version' => 'synthetic-0.1.0',
                    'created_at' => $ingestedAt,
                    'updated_at' => $ingestedAt,
                ],
                'observation' => null,
                'anomaly' => null,
                'ground_truth' => $groundTruth,
            ];
        }

        $resolution = [
            'submission_id' => $id,
            'canonical_item_id' => $item->id,
            'method' => Resolution::METHOD_FUSED,
            'confidence' => round($this->randomizer->getFloat(0.86, 0.99), 4),
            'candidates' => null,
            'reviewed' => false,
            'model_version' => 'synthetic-0.1.0',
            'created_at' => $ingestedAt,
            'updated_at' => $ingestedAt,
        ];

        $observation = [
            'submission_id' => $id,
            'country_id' => $country->id,
            'location_id' => $location->id,
            'canonical_item_id' => $item->id,
            'price' => $rawPrice,
            'currency_code' => $country->currency_code,
            'unit_code' => $unit->code,
            'quantity' => $quantity,
            'normalized_price_per_base_unit' => $reportedPricePerBaseUnit,
            'observed_on' => $date->toDateString(),
            'observed_at' => $collectedAt,
            'reporter_id' => $reporter->id,
            'source_id' => $source->id,
            'reputation_at_time' => $reporter->reputation,
            'is_valid' => true,
            'superseded_by_id' => null,
            'created_at' => $ingestedAt,
            'updated_at' => $ingestedAt,
        ];

        // Only obviously-wrong submissions carry a stored anomaly score here.
        // The real detector runs in Phase 6 and is scored against the labels;
        // pre-labelling everything would make that evaluation circular.
        $anomaly = null;
        if ($isErroneous && $errorType !== 'stale_copy') {
            $anomaly = [
                'submission_id' => $id,
                'score' => round($this->randomizer->getFloat(0.62, 0.95), 4),
                'verdict' => AnomalyScore::VERDICT_SUSPECT,
                'reasons' => json_encode([[
                    'code' => 'seeded_error',
                    'message' => 'Synthetic seed data: injected '.$errorType,
                ]]),
                'layer_scores' => null,
                'model_version' => 'synthetic-0.1.0',
                'created_at' => $ingestedAt,
            ];
        }

        return [
            'submission' => $submission,
            'resolution' => $resolution,
            'observation' => $observation,
            'anomaly' => $anomaly,
            'ground_truth' => $groundTruth,
        ];
    }

    /**
     * Apply one of the four mistakes reporters actually make.
     *
     * @return array{0: float, 1: string}
     */
    private function applyError(float $price, float $fxRate): array
    {
        return match ($this->randomizer->getInt(1, 4)) {
            // Priced per gram when the catalogue expects per kilo, or the
            // reverse. The most common real error, and the one unit
            // normalisation is supposed to catch.
            1 => [round($price / 1000, 4), 'unit_confusion'],
            // A misplaced decimal point.
            2 => [round($price * ($this->randomizer->getInt(0, 1) === 0 ? 10 : 0.1), 4), 'decimal_slip'],
            // Entered in USD rather than local currency.
            3 => [round($price / max(0.01, $fxRate * 7.6), 4), 'wrong_currency'],
            // Copied from an old note; plausible but out of date.
            default => [round($price * $this->randomizer->getFloat(0.55, 0.75), 4), 'stale_copy'],
        };
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @return Collection<int, Reporter>
     */
    private function createReporters(Country $country, $locations, int $perLocation)
    {
        $rows = [];
        $now = now();

        foreach ($locations as $location) {
            for ($i = 0; $i < $perLocation; $i++) {
                // Reporters differ in reliability from the start; a population
                // where everyone is equally good makes reputation pointless.
                $alpha = $this->randomizer->getFloat(2.0, 40.0);
                $beta = $this->randomizer->getFloat(2.0, 8.0);

                $rows[] = [
                    'country_id' => $country->id,
                    'location_id' => $location->id,
                    'external_ref' => Str::uuid()->toString(),
                    'display_name' => null,
                    'reputation' => round($alpha / ($alpha + $beta), 4),
                    'reputation_alpha' => round($alpha, 4),
                    'reputation_beta' => round($beta, 4),
                    'submissions_total' => 0,
                    'submissions_accepted' => 0,
                    'submissions_rejected' => 0,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'is_blocked' => false,
                    'blocked_reason' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('reporters')->insert($chunk);
        }

        return Reporter::query()->where('country_id', $country->id)->get();
    }

    /**
     * Pick the reporters who will act as a coordinated bad-actor cluster.
     *
     * Concentrated in one or two locations rather than scattered, because
     * coordinated suppression is what actually threatens an index like this and
     * scattered noise is already handled by the outlier layers.
     *
     * @param  Collection<int, Reporter>  $reporters
     * @return array<int, true>
     */
    private function chooseManipulators($reporters): array
    {
        $byLocation = $reporters->groupBy('location_id');

        if ($byLocation->isEmpty()) {
            return [];
        }

        // Drawn from the seeded randomizer rather than Collection::shuffle(),
        // which takes no seed — using it would have made the bad-actor cluster
        // vary between runs and quietly broken reproducibility.
        $keys = $byLocation->keys()->values()->all();
        $targetLocations = [];

        while (count($targetLocations) < min(2, count($keys))) {
            $pick = $keys[$this->randomizer->getInt(0, count($keys) - 1)];

            if (! in_array($pick, $targetLocations, true)) {
                $targetLocations[] = $pick;
            }
        }

        $chosen = [];
        foreach ($targetLocations as $locationId) {
            foreach ($byLocation[$locationId]->take(2) as $reporter) {
                $chosen[$reporter->id] = true;
            }
        }

        return $chosen;
    }

    /**
     * (location, item) pairs never observed anywhere in the series.
     *
     * Real coverage is not uniformly thin — some items are simply never
     * reported in some towns, and an index that has never seen that case will
     * be surprised by it in production.
     *
     * @param  list<string>  $locationSlugs
     * @param  list<string>  $itemCodes
     * @return array<string, true>
     */
    private function chooseBlindSpots(array $locationSlugs, array $itemCodes): array
    {
        $blindSpots = [];

        foreach ($locationSlugs as $slug) {
            foreach ($itemCodes as $code) {
                if ($this->randomizer->getFloat(0.0, 1.0) < self::BLIND_SPOT_RATE) {
                    $blindSpots[$slug.'|'.$code] = true;
                }
            }
        }

        return $blindSpots;
    }

    /**
     * Weeks in which a location reports nothing — conflict, outage, a reporter
     * who stopped.
     *
     * @param  list<string>  $locationSlugs
     * @return array<string, true>
     */
    private function chooseBlackoutWeeks(array $locationSlugs, int $weeks): array
    {
        $blackouts = [];

        foreach ($locationSlugs as $slug) {
            for ($week = 0; $week < $weeks; $week++) {
                if ($this->randomizer->getFloat(0.0, 1.0) < self::BLACKOUT_WEEK_RATE) {
                    $blackouts[$slug.'|'.$week] = true;
                }
            }
        }

        return $blackouts;
    }

    /**
     * @param  list<float>  $fxPath
     * @param  array<string, mixed>  $demoConfig
     */
    private function writeFxRates(Country $country, CarbonImmutable $startDate, array $fxPath, array $demoConfig): void
    {
        $startParallel = (float) ($demoConfig['fx_start_parallel'] ?? 5.0);
        $startOfficial = (float) ($demoConfig['fx_start_official'] ?? 4.8);

        $rows = [];
        $now = now();

        foreach ($fxPath as $day => $index) {
            $date = $startDate->addDays($day);

            $rows[] = [
                'country_id' => $country->id,
                'rate_date' => $date->toDateString(),
                'official_rate' => round($startOfficial, 8),
                'parallel_rate' => round($startParallel * $index, 8),
                'base_currency' => 'USD',
                'source' => 'synthetic',
                'is_manual' => true,
                'raw' => null,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('fx_rates')->insert($chunk);
        }
    }

    /**
     * Backfill each reporter's submission counters from what was generated.
     *
     * Counters that disagree with the rows would be an obvious tell that the
     * data is fabricated, and would break any UI that trusts them.
     */
    private function updateReporterStats(): void
    {
        DB::statement('
            UPDATE reporters r
            SET submissions_total = c.total,
                submissions_accepted = c.accepted,
                last_seen_at = c.last_seen
            FROM (
                SELECT reporter_id,
                       COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE status = ?) AS accepted,
                       MAX(ingested_at) AS last_seen
                FROM submissions
                WHERE reporter_id IS NOT NULL
                GROUP BY reporter_id
            ) c
            WHERE c.reporter_id = r.id
        ', [Submission::STATUS_RESOLVED]);
    }

    /**
     * @param  list<array<string, mixed>>  $submissions
     * @param  list<array<string, mixed>>  $resolutions
     * @param  list<array<string, mixed>>  $observations
     * @param  list<array<string, mixed>>  $anomalies
     * @param  list<array<string, mixed>>  $groundTruthSubmissions
     * @param  list<array<string, mixed>>  $groundTruthPrices
     */
    private function flush(
        array &$submissions,
        array &$resolutions,
        array &$observations,
        array &$anomalies,
        array &$groundTruthSubmissions,
        array &$groundTruthPrices,
    ): void {
        // Ordered so foreign keys are always satisfiable.
        $this->insertChunks('submissions', $submissions);
        $this->insertChunks('resolutions', $resolutions);
        $this->insertChunks('price_observations', $observations);
        $this->insertChunks('anomaly_scores', $anomalies);
        $this->insertChunks('qeema_eval.gt_submissions', $groundTruthSubmissions);
        $this->insertChunks('qeema_eval.gt_prices', $groundTruthPrices);

        $submissions = [];
        $resolutions = [];
        $observations = [];
        $anomalies = [];
        $groundTruthSubmissions = [];
        $groundTruthPrices = [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * Turn per-item reference prices into the per-base-unit prices the process wants.
     *
     * `reference_prices` in a country file are written the way a person thinks
     * about a shop: `eggs_30: 24.0` is twenty-four dinars for the tray,
     * `paracetamol_suspension_60ml: 8.0` is eight dinars for the bottle. The
     * price process works in base units — per piece, per litre — because that is
     * what an observation normalises to.
     *
     * Feeding one to the other unconverted priced a tray of thirty eggs at
     * twenty-four dinars *each*, and a sixty-millilitre bottle of paracetamol at
     * eight dinars *per litre*. The same mistake inflated some items thirtyfold
     * and deflated others sixteenfold, and every result still looked like a price.
     *
     * @param  array<string, float>  $references
     * @param  array<string, CanonicalItem>  $itemsByCode
     * @param  array<string, float>  $factors  unit code => factor to its base unit
     * @return array<string, float>
     */
    private function perBaseUnitReferences(array $references, array $itemsByCode, array $factors): array
    {
        $converted = [];

        foreach ($references as $code => $price) {
            $item = $itemsByCode[$code] ?? null;

            if ($item === null) {
                continue;
            }

            $packInBaseUnits = (float) $item->default_quantity * ($factors[$item->default_unit_code] ?? 0.0);

            if ($packInBaseUnits <= 0.0) {
                throw new RuntimeException(
                    "Item {$code} declares default_quantity {$item->default_quantity} in "
                    ."'{$item->default_unit_code}', which this country either does not define "
                    .'as a unit or defines with a non-positive conversion factor. Seeding a '
                    .'reference price would require guessing one.'
                );
            }

            $converted[$code] = $price / $packInBaseUnits;
        }

        return $converted;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     */
    private function floatMap(array $values): array
    {
        return array_map(static fn ($v): float => (float) $v, $values);
    }
}
