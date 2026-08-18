<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Is the headline number the right size?
|--------------------------------------------------------------------------
|
| Every other test in this suite asks whether the code does what the code was
| written to do. None of them asked whether the answer was the right size, and
| so a published Tripoli basket read 13,250 LYD — roughly ten times what a
| five-person household spends in a month — while 178 index tests passed.
|
| The failure was not a missing assertion. It was a missing *reference*: there
| was nothing in the repository that any figure could be wrong against. Toy
| fixtures agreeing with the implementation is not evidence, however many of
| them there are.
|
| So each country file declares what its basket ought to cost, with the outside
| source that justifies the range written beside it, and this walks every
| country file and checks. It is arithmetic over YAML — no database, no
| generator, no model — which is what makes it a check on the *definition*
| rather than on the pipeline.
|
| It is a tripwire for errors of magnitude, not a precision instrument. Errors
| of magnitude are the ones that survive review, because they look like numbers.
|
*/

/**
 * Cost one country's basket from its own configuration.
 *
 * A basket line is a quantity in a stated unit; a reference price is the price
 * of one catalogue item. Dividing by the item's own pack size is the whole
 * conversion — 60 ml of a 60 ml bottle is one bottle, 60 litres of a 20 litre
 * container is three.
 *
 * @param  array<string, mixed>  $config
 * @return array{total: float, lines: array<string, float>}
 */
function costBasketFromConfig(array $config): array
{
    /** @var array<string, array<string, mixed>> $items */
    $items = [];
    foreach ($config['canonical_items'] as $item) {
        $items[(string) $item['code']] = $item;
    }

    /** @var array<string, float> $references */
    $references = [];
    foreach (($config['demo']['reference_prices'] ?? []) as $code => $price) {
        $references[(string) $code] = (float) $price;
    }

    $total = 0.0;
    $lines = [];

    foreach ($config['basket']['items'] as $line) {
        $code = (string) $line['item'];

        expect(array_key_exists($code, $items))
            ->toBeTrue("Basket names {$code}, which is not a catalogue item.");
        expect(array_key_exists($code, $references))
            ->toBeTrue("No reference price for basket item {$code}.");

        $packSize = (float) $items[$code]['default_quantity'];

        expect($packSize > 0.0)
            ->toBeTrue("Item {$code} has a non-positive default_quantity.");

        // The basket's unit must be the item's own unit, or the division below
        // is comparing millilitres to pieces. The importer enforces this too;
        // asserting it here means a bad country file fails before anything is
        // written to a database.
        expect((string) $line['unit'])->toBe(
            (string) $items[$code]['default_unit_code'],
            "Basket line {$code} is stated in '{$line['unit']}' but the item is defined in "
            ."'{$items[$code]['default_unit_code']}'. Costing it would mean converting between "
            .'units the country file never related.',
        );

        $cost = ((float) $line['quantity'] / $packSize) * $references[$code];
        $lines[$code] = round($cost, 2);
        $total += $cost;
    }

    return ['total' => round($total, 2), 'lines' => $lines];
}

/** @return list<array{0: string, 1: array<string, mixed>}> */
function countryFiles(): array
{
    $out = [];

    foreach (glob(base_path('../countries/*.yaml')) ?: [] as $path) {
        $out[] = [basename($path), Yaml::parseFile($path)];
    }

    return $out;
}

it('has at least one country to check', function (): void {
    // Guards the guard. A glob that silently matches nothing would make every
    // assertion below vacuous and this file would pass forever.
    expect(countryFiles())->not->toBeEmpty();
});

it('costs a basket that is the right order of magnitude', function (): void {
    foreach (countryFiles() as [$name, $config]) {
        $band = $config['basket']['plausible_monthly_cost_local'] ?? null;

        expect($band)->not->toBeNull(
            "{$name} declares no plausible_monthly_cost_local. Every country must say what its "
            .'basket ought to cost, so that a figure of the wrong magnitude has something to be '
            .'wrong against.',
        );

        $result = costBasketFromConfig($config);

        expect($result['total'])
            ->toBeGreaterThanOrEqual(
                (float) $band['min'],
                "{$name}: basket costs {$result['total']}, below the declared floor of {$band['min']}. "
                .'Either the configuration is wrong or the floor is. Lines: '
                .json_encode($result['lines']),
            )
            ->toBeLessThanOrEqual(
                (float) $band['max'],
                "{$name}: basket costs {$result['total']}, above the declared ceiling of {$band['max']}. "
                .'Either the configuration is wrong or the ceiling is. Lines: '
                .json_encode($result['lines']),
            );
    }
});

it('lets no single item dominate the basket', function (): void {
    // The shape of the original bug: three of fifteen items were 92% of the
    // cost. A child's basket has no single line that matters that much, so a
    // line above half the total is a unit error long before it is a judgement
    // about children.
    foreach (countryFiles() as [$name, $config]) {
        $result = costBasketFromConfig($config);

        foreach ($result['lines'] as $code => $cost) {
            $share = $result['total'] > 0.0 ? $cost / $result['total'] : 0.0;

            expect($share)->toBeLessThan(
                0.5,
                "{$name}: {$code} is ".round($share * 100)."% of the whole basket ({$cost} of "
                ."{$result['total']}). One line carrying half the basket is what a unit-conversion "
                .'error looks like.',
            );
        }
    }
});
