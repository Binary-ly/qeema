<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\BasketLink;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Services\Index\ChainLinker;
use App\Services\Index\IndexCalculator;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Chain-linking across a basket revision
|--------------------------------------------------------------------------
|
| Revise the basket and `cost_local` steps, because a different bundle is being
| priced. The step is not inflation, but it is indistinguishable from inflation
| to anyone plotting the series.
|
| The decisive test holds every price constant across the revision. Under those
| conditions the correct index does not move at all, while the cost must jump —
| so the two assertions together prove the link is doing its job rather than the
| numbers merely looking plausible. Expected values are hand-computed:
|
|   v1 = 2kg rice @ 5 + 1l oil @ 8                      = 18
|   v2 = 2kg rice @ 5 + 1l oil @ 8 + 3 soap @ 2         = 24
|   link factor = 24 / 18                               = 1.3333…
|   reference(v2) = reference(v1) × factor = 18 × 1.333… = 24
|   level under either basket = 100 × cost / reference   = 100
|
*/

const LINK_BASE_DATE = '2026-01-01';
const LINK_CHANGEOVER = '2026-04-01';
const LINK_LAST_V1_DAY = '2026-03-31';

beforeEach(function (): void {
    $this->country = Country::factory()->create([
        'currency_code' => 'XTS',
        'is_active' => true,
        'index_config' => [
            'observation_window_days' => 7,
            'recency_half_life_days' => 3,
            'min_observations_for_ci' => 3,
            'bootstrap_draws' => 50,
            'base_date' => LINK_BASE_DATE,
        ],
        'fx_config' => ['provider' => 'manual', 'rate_type' => 'parallel', 'max_staleness_days' => 7],
    ]);

    $this->location = Location::factory()->create([
        'country_id' => $this->country->id,
        'is_active' => true,
    ]);

    $this->rice = CanonicalItem::factory()->create(['country_id' => $this->country->id, 'code' => 'rice']);
    $this->oil = CanonicalItem::factory()->create(['country_id' => $this->country->id, 'code' => 'oil']);
    $this->soap = CanonicalItem::factory()->create(['country_id' => $this->country->id, 'code' => 'soap']);

    // v1 runs to the day before the changeover; v2 takes over from it.
    $this->v1 = Basket::factory()->create([
        'country_id' => $this->country->id,
        'version' => 1,
        'effective_from' => LINK_BASE_DATE,
        'effective_to' => LINK_LAST_V1_DAY,
    ]);

    $this->v2 = Basket::factory()->create([
        'country_id' => $this->country->id,
        'version' => 2,
        'effective_from' => LINK_CHANGEOVER,
        'effective_to' => null,
    ]);

    linkItems($this->v1, [[$this->rice, 0.6, 2], [$this->oil, 0.4, 1]]);
    linkItems($this->v2, [[$this->rice, 0.5, 2], [$this->oil, 0.3, 1], [$this->soap, 0.2, 3]]);
});

/**
 * @param  list<array{0: CanonicalItem, 1: float, 2: float}>  $entries
 */
function linkItems(Basket $basket, array $entries): void
{
    foreach ($entries as [$item, $weight, $quantity]) {
        BasketItem::factory()->create([
            'basket_id' => $basket->id,
            'canonical_item_id' => $item->id,
            'weight' => $weight,
            'quantity' => $quantity,
            'unit_code' => 'kg',
        ]);
    }
}

function linkPrice(CanonicalItem $item, float $price, string $date, ?Location $at = null): void
{
    $location = $at ?? test()->location;

    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => $location->id,
    ]);

    PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => test()->country->id,
        'location_id' => $location->id,
        'canonical_item_id' => $item->id,
        'normalized_price_per_base_unit' => $price,
        'observed_on' => $date,
        'observed_at' => $date.' 12:00:00',
        'reputation_at_time' => 1.0,
    ]);
}

/** Every item priced on a date, at prices that do not move unless asked to. */
function linkPricesOn(string $date, float $multiplier = 1.0, ?Location $at = null): void
{
    linkPrice(test()->rice, 5.0 * $multiplier, $date, $at);
    linkPrice(test()->oil, 8.0 * $multiplier, $date, $at);
    linkPrice(test()->soap, 2.0 * $multiplier, $date, $at);
}

function linkLevelOn(Basket $basket, string $date): ?float
{
    $snapshot = (new IndexCalculator)->calculate(
        test()->country,
        test()->location,
        $basket,
        CarbonImmutable::parse($date),
    );

    return $snapshot->index_level === null ? null : (float) $snapshot->index_level;
}

it('anchors the first basket at the country base date, where the level is exactly 100', function (): void {
    linkPricesOn(LINK_BASE_DATE);

    (new ChainLinker)->establish($this->country, $this->v1);

    $anchor = BasketLink::anchorFor($this->v1, $this->location);

    expect($anchor)->not->toBeNull()
        ->and($anchor->method)->toBe(BasketLink::METHOD_BASE_PERIOD)
        ->and($anchor->reference_cost)->toBe(18.0)
        ->and(linkLevelOn($this->v1, LINK_BASE_DATE))->toBe(100.0);
});

it('keeps the level continuous across a revision while the cost visibly jumps', function (): void {
    // Prices are identical on both sides of the changeover, so every movement
    // in the published series would be an artefact of the revision alone.
    linkPricesOn(LINK_BASE_DATE);
    linkPricesOn(LINK_LAST_V1_DAY);
    linkPricesOn(LINK_CHANGEOVER);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $linker->establish($this->country, $this->v2);

    $calculator = new IndexCalculator;

    $before = $calculator->calculate(
        $this->country, $this->location, $this->v1, CarbonImmutable::parse(LINK_LAST_V1_DAY),
    );
    $after = $calculator->calculate(
        $this->country, $this->location, $this->v2, CarbonImmutable::parse(LINK_CHANGEOVER),
    );

    // The cost genuinely steps: the basket now contains soap.
    expect((float) $before->cost_local)->toBe(18.0)
        ->and((float) $after->cost_local)->toBe(24.0);

    // The index does not, because that step is the revision and not a price.
    expect((float) $before->index_level)->toBe(100.0)
        ->and((float) $after->index_level)->toBe(100.0);
});

it('records the link factor and what each basket cost on the link date', function (): void {
    linkPricesOn(LINK_BASE_DATE);
    linkPricesOn(LINK_LAST_V1_DAY);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $linker->establish($this->country, $this->v2);

    $anchor = BasketLink::anchorFor($this->v2, $this->location);

    expect($anchor->method)->toBe(BasketLink::METHOD_CHAINED)
        ->and($anchor->link_factor)->toBeGreaterThan(1.3333)
        ->and($anchor->link_factor)->toBeLessThan(1.3334)
        ->and($anchor->previous_cost)->toBe(18.0)
        ->and($anchor->linked_cost)->toBe(24.0)
        ->and($anchor->link_date->toDateString())->toBe(LINK_LAST_V1_DAY)
        ->and($anchor->previous_basket_id)->toBe($this->v1->id);
});

it('moves the level with prices once the new basket is in force', function (): void {
    linkPricesOn(LINK_BASE_DATE);
    linkPricesOn(LINK_LAST_V1_DAY);
    // Everything 10% dearer the day after the changeover.
    linkPricesOn('2026-04-02', multiplier: 1.1);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $linker->establish($this->country, $this->v2);

    expect(linkLevelOn($this->v2, '2026-04-02'))->toBe(110.0);
});

it('refuses to anchor on a basket it could not fully price', function (): void {
    // Soap is never reported, so v2 can only ever be three-quarters priced and
    // its cost is not the cost of that basket. Anchoring on it would fold the
    // gap permanently into every level derived from the anchor (D-20).
    linkPrice($this->rice, 5.0, LINK_BASE_DATE);
    linkPrice($this->oil, 8.0, LINK_BASE_DATE);
    linkPrice($this->rice, 5.0, LINK_LAST_V1_DAY);
    linkPrice($this->oil, 8.0, LINK_LAST_V1_DAY);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $report = $linker->establish($this->country, $this->v2);

    expect(BasketLink::anchorFor($this->v2, $this->location))->toBeNull()
        ->and($report->anchoredCount())->toBe(0)
        ->and($report->skips())->not->toBeEmpty();
});

it('publishes no level rather than a meaningless one when a basket is unanchored', function (): void {
    linkPricesOn(LINK_CHANGEOVER);

    // No linker run at all.
    expect(linkLevelOn($this->v2, LINK_CHANGEOVER))->toBeNull();
});

it('does not rewrite an anchor that already exists', function (): void {
    linkPricesOn(LINK_BASE_DATE);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);

    $original = BasketLink::anchorFor($this->v1, $this->location)->reference_cost;

    // Prices double, and the anchor is re-established. Silently taking the new
    // reference would restate every level ever published behind it (D-21).
    linkPricesOn(LINK_BASE_DATE, multiplier: 2.0);
    $report = $linker->establish($this->country, $this->v1);

    expect(BasketLink::anchorFor($this->v1, $this->location)->reference_cost)->toBe($original)
        ->and($report->anchoredCount())->toBe(0)
        ->and($report->skips()[0]['reason'])->toContain('already anchored');
});

it('replaces an anchor when overwriting is asked for explicitly', function (): void {
    linkPricesOn(LINK_BASE_DATE);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);

    linkPricesOn(LINK_BASE_DATE, multiplier: 2.0);
    $linker->establish($this->country, $this->v1, force: true);

    // Two observations per item now, one at each price, so the estimate lands
    // between them rather than on either — the point is that it moved.
    expect(BasketLink::anchorFor($this->v1, $this->location)->reference_cost)
        ->toBeGreaterThan(18.0);
});

it('borrows the country median factor for a location that cannot measure its own', function (): void {
    $thin = Location::factory()->create([
        'country_id' => $this->country->id,
        'is_active' => true,
    ]);

    // The main location can price both baskets on the link date.
    linkPricesOn(LINK_BASE_DATE);
    linkPricesOn(LINK_LAST_V1_DAY);

    // The thin one can price v1 but never sees soap, so it cannot measure the
    // effect of the revision for itself.
    linkPrice($this->rice, 5.0, LINK_BASE_DATE, at: $thin);
    linkPrice($this->oil, 8.0, LINK_BASE_DATE, at: $thin);
    linkPrice($this->rice, 5.0, LINK_LAST_V1_DAY, at: $thin);
    linkPrice($this->oil, 8.0, LINK_LAST_V1_DAY, at: $thin);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $report = $linker->establish($this->country, $this->v2);

    $borrowed = BasketLink::anchorFor($this->v2, $thin);

    expect($borrowed)->not->toBeNull()
        ->and($borrowed->usedCountryFallback())->toBeTrue()
        ->and($report->fallbackCount())->toBe(1)
        // Marked as borrowed rather than presented as measured here.
        ->and($borrowed->notes)->toContain('country median factor');
});

it('leaves a location unanchored when there is nothing to chain from', function (): void {
    $isolated = Location::factory()->create([
        'country_id' => $this->country->id,
        'is_active' => true,
    ]);

    linkPricesOn(LINK_BASE_DATE);
    linkPricesOn(LINK_LAST_V1_DAY);

    $linker = new ChainLinker;
    $linker->establish($this->country, $this->v1);
    $linker->establish($this->country, $this->v2);

    // It never had a v1 anchor, so there is no earlier reference to carry
    // forward and no honest level to publish.
    expect(BasketLink::anchorFor($this->v1, $isolated))->toBeNull()
        ->and(BasketLink::anchorFor($this->v2, $isolated))->toBeNull();
});

it('anchors at the start of the series when no base period is configured', function (): void {
    // A fixed base date only stays meaningful while the deployment holds data
    // covering it, so a country may leave it unset. The base period is then the
    // first day the basket could be priced in full — measured rather than
    // asserted, and recorded so it cannot drift afterwards.
    $this->country->forceFill([
        'index_config' => array_merge($this->country->index_config, ['base_date' => null]),
    ])->save();

    // Nothing at the nominal base date; the series genuinely starts later.
    linkPricesOn('2026-02-10');

    (new ChainLinker)->establish($this->country->fresh(), $this->v1);

    $anchor = BasketLink::anchorFor($this->v1, $this->location);

    expect($anchor)->not->toBeNull()
        ->and($anchor->link_date->toDateString())->toBe('2026-02-10')
        ->and($anchor->notes)->toContain('No base date configured')
        ->and(linkLevelOn($this->v1, '2026-02-10'))->toBe(100.0);
});

it('honours a configured base period exactly rather than moving it', function (): void {
    // The operator asserted when their series starts. If there is no data there,
    // that is worth being told about — silently anchoring on a later date would
    // publish a series whose 100 is not the date they documented.
    linkPricesOn('2026-02-10');

    $report = (new ChainLinker)->establish($this->country, $this->v1);

    expect(BasketLink::anchorFor($this->v1, $this->location))->toBeNull()
        ->and($report->skips()[0]['reason'])->toContain(LINK_BASE_DATE);
});

it('hands already-published snapshots back for recomputation when anchored', function (): void {
    linkPricesOn(LINK_BASE_DATE);

    // Published before any anchor existed, so it carries no level. The
    // publisher will not revisit a date it has already done, and nothing else
    // would ever give this row a level.
    $snapshot = (new IndexCalculator)->calculate(
        $this->country, $this->location, $this->v1, CarbonImmutable::parse(LINK_BASE_DATE),
    );

    expect($snapshot->index_level)->toBeNull()
        ->and($snapshot->is_stale)->toBeFalse();

    (new ChainLinker)->establish($this->country, $this->v1);

    expect($snapshot->fresh()->is_stale)->toBeTrue();
});

it('leaves a snapshot that already has a level alone', function (): void {
    linkPricesOn(LINK_BASE_DATE);

    (new ChainLinker)->establish($this->country, $this->v1);

    $snapshot = (new IndexCalculator)->calculate(
        $this->country, $this->location, $this->v1, CarbonImmutable::parse(LINK_BASE_DATE),
    );

    // Re-running the linker must not restate a figure that was computed against
    // this same anchor and is correct.
    (new ChainLinker)->establish($this->country, $this->v1, force: true);

    expect($snapshot->fresh()->is_stale)->toBeFalse();
});
