<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| A price with no citation is a guess wearing a decimal point
|--------------------------------------------------------------------------
|
| The drinking-water reference price sat at 3.00 LYD because a single news
| article said so. The article was five months old and described the *start* of
| a 50% spike; by the time anyone looked, the real figure was 4 in Tripoli and 5
| in Ubari. Nobody noticed, because a number in a config file looks exactly like
| a number that was checked.
|
| Nothing here can tell whether a price is *right* — that needs the world. What
| it can do is refuse to let a price claim a source it does not have, and stop
| the number of sourced prices going backwards.
|
| The ratchet matters more than it looks. Requiring every price to be sourced at
| once would mean sourcing eighteen of them before any of this could land, which
| is how a good rule becomes a rule nobody adopts. Requiring that the count never
| *falls* makes it incremental, and makes deleting a citation a failing test.
|
*/

/** @return list<array{0: string, 1: array<string, mixed>}> */
function countryConfigs(): array
{
    $out = [];

    foreach (glob(base_path('../countries/*.yaml')) ?: [] as $path) {
        $out[] = [basename($path), Yaml::parseFile($path)];
    }

    return $out;
}

it('has country files to check', function (): void {
    // A glob matching nothing would make every assertion below vacuous.
    expect(countryConfigs())->not->toBeEmpty();
});

it('never lets a provenance entry disagree with the price it documents', function (): void {
    // The failure this exists for: someone edits the price and leaves the
    // citation behind, so the file now cites a source for a number that source
    // never gave.
    foreach (countryConfigs() as [$name, $config]) {
        $prices = $config['demo']['reference_prices'] ?? [];
        $provenance = $config['demo']['reference_price_provenance'] ?? [];

        foreach ($provenance as $code => $entry) {
            expect(array_key_exists($code, $prices))->toBeTrue(
                "{$name}: provenance documents {$code}, which has no reference price."
            );

            expect((float) $entry['value'])->toEqualWithDelta(
                (float) $prices[$code],
                0.0001,
                "{$name}: {$code} is priced at {$prices[$code]} but its provenance claims "
                ."{$entry['value']}. One of them was edited without the other.",
            );
        }
    }
});

it('requires every cited source to be checkable', function (): void {
    // A citation nobody can follow is decoration. URL and date are the minimum
    // that lets a reader go and disagree.
    foreach (countryConfigs() as [$name, $config]) {
        foreach (($config['demo']['reference_price_provenance'] ?? []) as $code => $entry) {
            expect($entry['sources'] ?? [])->not->toBeEmpty(
                "{$name}: {$code} declares provenance with no sources at all."
            );

            foreach ($entry['sources'] as $i => $source) {
                expect($source['url'] ?? '')->toStartWith(
                    'http',
                    "{$name}: {$code} source #{$i} has no URL.",
                );
                expect((string) ($source['date'] ?? ''))->not->toBe(
                    '',
                    "{$name}: {$code} source #{$i} has no date. A price without a date cannot "
                    .'be judged stale, which is exactly how the water price went wrong.',
                );
                expect(trim((string) ($source['says'] ?? '')))->not->toBe(
                    '',
                    "{$name}: {$code} source #{$i} does not say what the source actually stated.",
                );
            }

            // Both of these are claims *about* multiple sources, so both need
            // more than one. `contested` is the more interesting of the two:
            // it says credible sources disagree materially and the disagreement
            // is itself the finding — cooking gas is 1.50 officially and around
            // 60 on the street, and pretending to a single number there would
            // be worse than admitting the spread.
            if (in_array($entry['confidence'] ?? null, ['corroborated', 'contested'], true)) {
                expect(count($entry['sources']))->toBeGreaterThanOrEqual(
                    2,
                    "{$name}: {$code} claims '{$entry['confidence']}' on ".count($entry['sources'])
                    .' source. Both corroborated and contested are statements about how several '
                    .'sources relate, so neither can rest on one.',
                );
            }
        }
    }
});

it('never sources fewer prices than it did before', function (): void {
    foreach (countryConfigs() as [$name, $config]) {
        $required = $config['demo']['minimum_sourced_prices'] ?? null;

        if ($required === null) {
            continue;
        }

        $sourced = count($config['demo']['reference_price_provenance'] ?? []);

        expect($sourced)->toBeGreaterThanOrEqual(
            (int) $required,
            "{$name}: {$sourced} reference price(s) carry provenance, but the file requires at "
            ."least {$required}. Raise the ratchet when you add citations; never lower it to "
            .'make this pass.',
        );
    }
});

it('reports how much of the basket is still guesswork', function (): void {
    // Not an assertion — a standing reminder. Most of these prices are still
    // plausible-looking numbers nobody checked, and that fact should be visible
    // on every run rather than buried in a file.
    foreach (countryConfigs() as [$name, $config]) {
        $prices = $config['demo']['reference_prices'] ?? [];
        $sourced = array_keys($config['demo']['reference_price_provenance'] ?? []);
        $unsourced = array_values(array_diff(array_keys($prices), $sourced));

        if ($unsourced !== []) {
            fwrite(STDERR, sprintf(
                "\n  %s: %d of %d reference prices are unsourced — %s\n",
                $name,
                count($unsourced),
                count($prices),
                implode(', ', array_slice($unsourced, 0, 8)).(count($unsourced) > 8 ? ', …' : ''),
            ));
        }

        expect(count($prices))->toBeGreaterThan(0, "{$name} declares no reference prices.");
    }
});
