<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Location;
use App\Models\Unit;
use App\Support\CountryConfig\CountryConfigException;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Country configuration
|--------------------------------------------------------------------------
|
| Country config is the mechanism that keeps the platform country-agnostic
| (C3), and it is what a self-hoster will edit first. Its validation is
| therefore tested as carefully as the index maths.
|
*/

/**
 * A minimal but valid configuration, deliberately for a fictional country.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validConfig(array $overrides = []): array
{
    $base = [
        'country' => [
            'code' => 'ZZ',
            'name' => 'Testland',
            'name_local' => 'تستلاند',
            'currency' => ['code' => 'ZZD', 'symbol' => '¤', 'minor_units' => 3],
            'timezone' => 'UTC',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'admin_labels' => ['admin1' => 'Province', 'admin2' => 'District'],
        ],
        'units' => [
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'base_unit_code' => 'kg', 'factor_to_base' => 1],
            ['code' => 'g', 'name' => 'Gram', 'dimension' => 'mass', 'base_unit_code' => 'kg', 'factor_to_base' => 0.001],
        ],
        'locations' => [
            ['name' => 'Alpha', 'slug' => 'alpha', 'latitude' => 10.0, 'longitude' => 20.0],
            ['name' => 'Beta', 'slug' => 'beta', 'latitude' => 11.0, 'longitude' => 21.0],
        ],
        'canonical_items' => [
            ['code' => 'rice', 'name_en' => 'Rice', 'name_local' => 'أرز', 'category' => 'staples', 'default_unit_code' => 'kg', 'variants' => ['ارز', 'rice']],
            ['code' => 'flour', 'name_en' => 'Flour', 'category' => 'staples', 'default_unit_code' => 'kg'],
        ],
        'basket' => [
            'name' => 'Test Basket',
            'version' => 1,
            'effective_from' => '2026-01-01',
            'items' => [
                ['item' => 'rice', 'weight' => 0.6, 'quantity' => 2, 'unit' => 'kg', 'category' => 'staples'],
                ['item' => 'flour', 'weight' => 0.4, 'quantity' => 3, 'unit' => 'kg', 'category' => 'staples'],
            ],
        ],
        'sources' => [
            ['type' => 'reporter', 'slug' => 'reporters', 'name' => 'Reporters'],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

/**
 * Write a config to a temporary YAML file and return the path.
 *
 * @param  array<string, mixed>  $config
 */
function writeConfig(array $config, string $name = 'zz.yaml'): string
{
    $dir = sys_get_temp_dir().'/qeema-config-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $path = $dir.'/'.$name;
    file_put_contents($path, Yaml::dump($config, 6));

    return $path;
}

describe('loading', function () {
    it('loads a valid configuration', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        expect($config['country']['code'])->toBe('ZZ')
            ->and($config['basket']['items'])->toHaveCount(2);
    });

    it('reports a missing file rather than throwing something opaque', function () {
        expect(fn () => (new CountryConfigLoader)->load('/nowhere/nope.yaml'))
            ->toThrow(CountryConfigException::class, 'File does not exist');
    });

    it('reports malformed YAML', function () {
        $dir = sys_get_temp_dir().'/qeema-bad-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/bad.yaml', "country:\n  code: ZZ\n   bad indent: x\n");

        expect(fn () => (new CountryConfigLoader)->load($dir.'/bad.yaml'))
            ->toThrow(CountryConfigException::class, 'YAML is malformed');
    });

    it('lists every problem at once rather than only the first', function () {
        // Someone adding a country should get one list to work through, not a
        // dozen round trips each revealing the next mistake.
        try {
            (new CountryConfigLoader)->load(writeConfig([
                'country' => ['code' => 'TOOLONG', 'currency' => ['code' => 'X']],
            ]));
            $problems = [];
        } catch (CountryConfigException $e) {
            $problems = $e->problems;
        }

        expect(count($problems))->toBeGreaterThanOrEqual(2);
    });

    it('loads only the requested countries from a directory', function () {
        $path = writeConfig(validConfig());
        $dir = dirname($path);
        file_put_contents(
            $dir.'/qq.yaml',
            Yaml::dump(validConfig(['country' => ['code' => 'QQ', 'name' => 'Otherland']]), 6)
        );

        $all = (new CountryConfigLoader)->loadDirectory($dir);
        $one = (new CountryConfigLoader)->loadDirectory($dir, ['QQ']);

        expect($all)->toHaveCount(2)
            ->and($one)->toHaveCount(1)
            ->and($one[0]['country']['code'])->toBe('QQ');
    });
});

describe('validation catches configuration mistakes', function () {
    it('rejects basket weights that do not sum to one', function () {
        // The invariant that makes coverage meaningful. A basket summing to 0.9
        // would understate coverage on every snapshot, permanently and silently.
        $config = validConfig();
        $config['basket']['items'][0]['weight'] = 0.5;

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'weights must sum to 1.0');
    });

    it('names the size of the weighting error', function () {
        $config = validConfig();
        $config['basket']['items'][0]['weight'] = 0.5;

        try {
            (new CountryConfigLoader)->load(writeConfig($config));
            $message = '';
        } catch (CountryConfigException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('0.900000')->and($message)->toContain('-0.100000');
    });

    it('rejects a basket referencing an unknown item', function () {
        $config = validConfig();
        $config['basket']['items'][0]['item'] = 'no_such_item';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "unknown canonical item 'no_such_item'");
    });

    it('rejects a basket using an unknown unit', function () {
        $config = validConfig();
        $config['basket']['items'][0]['unit'] = 'furlong';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "unknown unit 'furlong'");
    });

    it('rejects a unit whose base unit is not defined', function () {
        $config = validConfig();
        $config['units'][] = ['code' => 'oz', 'name' => 'Ounce', 'dimension' => 'mass', 'base_unit_code' => 'stone', 'factor_to_base' => 0.028];

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "base_unit_code 'stone'");
    });

    it('rejects a non-positive conversion factor', function () {
        $config = validConfig();
        $config['units'][1]['factor_to_base'] = 0;

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'factor_to_base must be a positive number');
    });

    it('rejects a default locale that is not offered', function () {
        $config = validConfig();
        $config['country']['default_locale'] = 'fr';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'is not present in country.locales');
    });

    it('rejects a half-specified coordinate pair', function () {
        $config = validConfig();
        unset($config['locations'][0]['longitude']);

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'only one of latitude/longitude');
    });

    it('rejects an out-of-range latitude', function () {
        $config = validConfig();
        $config['locations'][0]['latitude'] = 200;

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'latitude must be between -90 and 90');
    });

    it('rejects duplicate location slugs', function () {
        $config = validConfig();
        $config['locations'][1]['slug'] = 'alpha';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "duplicates slug 'alpha'");
    });

    it('rejects duplicate canonical item codes', function () {
        $config = validConfig();
        $config['canonical_items'][1]['code'] = 'rice';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "duplicates code 'rice'");
    });

    it('rejects an item listed twice in one basket', function () {
        $config = validConfig();
        $config['basket']['items'][1]['item'] = 'rice';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "lists 'rice' more than once");
    });

    it('rejects a missing required section', function () {
        $config = validConfig();
        unset($config['basket']);

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, "Missing required section 'basket'");
    });

    it('rejects a non-integer minor unit count', function () {
        $config = validConfig();
        $config['country']['currency']['minor_units'] = 'three';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'minor_units must be an integer');
    });
});

describe('importing', function () {
    it('imports a whole country in one pass', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        $summary = (new CountryConfigImporter)->import($config);

        expect($summary->countryCode)->toBe('ZZ')
            ->and($summary->units)->toBe(2)
            ->and($summary->locations)->toBe(2)
            ->and($summary->canonicalItems)->toBe(2)
            ->and($summary->basketItems)->toBe(2)
            ->and(Country::query()->count())->toBe(1)
            ->and(Location::query()->count())->toBe(2)
            ->and(Unit::query()->count())->toBe(2);
    });

    it('preserves a currency with three decimal places', function () {
        // Assuming two decimals — as most currency code does — would misprice
        // every figure in a country using a millesimal subdivision.
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        (new CountryConfigImporter)->import($config);

        expect(Country::query()->firstOrFail()->currency_minor_units)->toBe(3);
    });

    it('is idempotent across repeated imports', function () {
        // Re-running is how an operator applies an edit, so it must not
        // duplicate rows.
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));
        $importer = new CountryConfigImporter;

        $importer->import($config);
        $before = CanonicalItemVariant::query()->count();
        $second = $importer->import($config);

        expect(Country::query()->count())->toBe(1)
            ->and(Location::query()->count())->toBe(2)
            ->and(CanonicalItemVariant::query()->count())->toBe($before)
            ->and($second->variants)->toBe(0);
    });

    it('applies an edit on re-import rather than creating a second country', function () {
        $importer = new CountryConfigImporter;
        $importer->import((new CountryConfigLoader)->load(writeConfig(validConfig())));

        $edited = validConfig();
        $edited['country']['name'] = 'Renamed';
        $edited['locations'][0]['population_estimate'] = 42000;
        $importer->import((new CountryConfigLoader)->load(writeConfig($edited)));

        expect(Country::query()->count())->toBe(1)
            ->and(Country::query()->firstOrFail()->name)->toBe('Renamed')
            ->and(Location::query()->where('slug', 'alpha')->firstOrFail()->population_estimate)->toBe(42000);
    });

    it('normalises variants so hamza spellings collapse together', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        (new CountryConfigImporter)->import($config);

        $rice = CanonicalItem::query()->where('code', 'rice')->firstOrFail();
        $normalised = $rice->variants->pluck('normalized_text')->all();

        // 'أرز' (name_local) and 'ارز' (variant) differ only by a hamza and must
        // become one variant, not two.
        expect($normalised)->toContain('ارز')
            ->and(array_count_values($normalised)['ارز'] ?? 0)->toBe(1);
    });

    it('indexes the catalogue names themselves as variants', function () {
        // A reporter typing the catalogue name exactly should hit the lexical
        // index rather than falling through to semantic search.
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        (new CountryConfigImporter)->import($config);

        $rice = CanonicalItem::query()->where('code', 'rice')->firstOrFail();

        expect($rice->variants->pluck('normalized_text')->all())->toContain('rice');
    });

    it('tags variant locale by script', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        (new CountryConfigImporter)->import($config);

        $rice = CanonicalItem::query()->where('code', 'rice')->firstOrFail();
        $byText = $rice->variants->keyBy('normalized_text');

        expect($byText['ارز']->locale)->toBe('ar')
            ->and($byText['rice']->locale)->toBe('en');
    });

    it('produces a basket whose weights sum to one', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig()));

        (new CountryConfigImporter)->import($config);

        expect(Basket::query()->firstOrFail()->hasValidWeights())->toBeTrue();
    });
});

describe('the shipped Libya configuration', function () {
    it('is valid and imports cleanly', function () {
        // The default config is a deliverable in its own right; a mistake in it
        // would break the demo everything else is judged on.
        $path = base_path('../countries/ly.yaml');
        expect(file_exists($path))->toBeTrue();

        $config = (new CountryConfigLoader)->load($path);
        $summary = (new CountryConfigImporter)->import($config);

        expect($summary->countryCode)->toBe('LY')
            ->and($summary->basketItems)->toBe(15)
            ->and($summary->locations)->toBeGreaterThanOrEqual(15);
    });

    it('has a basket covering every mandated category', function () {
        $config = (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'));
        (new CountryConfigImporter)->import($config);

        $categories = Basket::query()->firstOrFail()->items->pluck('category')->unique()->values()->all();

        expect($categories)->toContain(
            'infant_nutrition', 'staples', 'protein', 'produce',
            'medicine', 'school', 'hygiene', 'fuel', 'water',
        );
    });

    it('uses three decimal places for the dinar', function () {
        $config = (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'));
        (new CountryConfigImporter)->import($config);

        expect(Country::query()->where('code', 'LY')->firstOrFail()->currency_minor_units)->toBe(3);
    });

    it('keeps a country an operator switched off switched off across imports', function () {
        // Whether a configured country is live on a deployment is the
        // operator's call. The live deployment switched its second country
        // off because sixteen towns of zeros dragged the health endpoint to
        // "degraded", and the next config import silently switched it back.
        $config = (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'));
        $importer = new CountryConfigImporter;
        $importer->import($config);

        $country = Country::query()->where('code', 'LY')->firstOrFail();
        expect($country->is_active)->toBeTrue();

        $country->forceFill(['is_active' => false])->save();
        $importer->import($config);

        expect(Country::query()->where('code', 'LY')->firstOrFail()->is_active)->toBeFalse();
    });
});

describe('the reference income', function () {
    /** @return array<string, mixed> */
    function referenceIncome(): array
    {
        return [
            'amount' => 1000,
            'period' => 'month',
            'label_en' => 'the legal minimum monthly wage',
            'label_local' => 'الحد الأدنى للأجر',
            'sources' => [[
                'url' => 'https://example.test/law-16-2023',
                'date' => '2023-05-22',
                'says' => 'Article 1: no less than one thousand for every worker.',
            ]],
        ];
    }

    it('refuses a figure with no source behind it', function () {
        // The page states every basket as a share of this number. An
        // unsourced denominator would be a guess published under every
        // figure on the site.
        $config = validConfig(['reference_income' => referenceIncome()]);
        unset($config['reference_income']['sources']);

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'reference_income.sources');
    });

    it('refuses a period the basket cannot be set against', function () {
        $config = validConfig(['reference_income' => referenceIncome()]);
        $config['reference_income']['period'] = 'week';

        expect(fn () => (new CountryConfigLoader)->load(writeConfig($config)))
            ->toThrow(CountryConfigException::class, 'reference_income.period');
    });

    it('imports the figure with its citation and exposes it on the country', function () {
        $config = (new CountryConfigLoader)->load(writeConfig(validConfig(['reference_income' => referenceIncome()])));
        (new CountryConfigImporter)->import($config);

        $income = Country::query()->where('code', 'ZZ')->firstOrFail()->referenceIncome();

        expect($income)->not->toBeNull()
            ->and($income['amount'])->toBe(1000.0)
            ->and($income['label_en'])->toBe('the legal minimum monthly wage')
            ->and($income['sources'][0]['url'])->toBe('https://example.test/law-16-2023');
    });

    it('leaves a country that declares none uncompared rather than guessed', function () {
        (new CountryConfigImporter)->import((new CountryConfigLoader)->load(writeConfig(validConfig())));

        expect(Country::query()->where('code', 'ZZ')->firstOrFail()->referenceIncome())->toBeNull();
    });
});
