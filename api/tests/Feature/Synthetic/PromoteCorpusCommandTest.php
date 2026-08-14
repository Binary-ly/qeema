<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Promotion turns a test corpus into catalogue vocabulary, which is the single
 * most effective thing measured on this platform — +19.1 points of top-1 on
 * held-out wordings — and also the easiest way to quietly poison a catalogue.
 * These tests are mostly about the second half of that sentence.
 */
beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/qeema-promote-'.bin2hex(random_bytes(6));

    mkdir($this->directory.'/corpus', recursive: true);
    config(['qeema.countries_path' => $this->directory]);

    file_put_contents($this->directory.'/xx.yaml', <<<'YAML'
    country:
      code: XX
      name: Example
    canonical_items:
      # A comment a maintainer wrote and would like to keep.
      - code: rice_1kg
        name_en: Rice
        name_local: أرز
        category: staples
        default_unit_code: kg
        variants: [ارز, rice]

      - code: cooking_oil_1l
        name_en: Cooking oil
        name_local: زيت طعام
        category: staples
        default_unit_code: litre
        variants: [زيت, oil]

      - code: eggs_30
        name_en: Eggs
        name_local: بيض
        category: protein
        default_unit_code: tray
        variants: [بيض]
    YAML);

    $this->writeCorpus = function (array $corpus): void {
        file_put_contents(
            $this->directory.'/corpus/xx.json',
            json_encode($corpus, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    };

    ($this->writeCorpus)([
        'items' => [
            'rice_1kg' => ['رز مصري', 'ارز حبة قصيرة', 'ارز'],
            'cooking_oil_1l' => ['زيت عباد الشمس', 'زيت قلي'],
            'eggs_30' => ['دحي', 'طبق دحي'],
        ],
        'hold' => ['eggs_30'],
        'distractors' => ['زيت زيتون بكر'],
    ]);

    $this->variantsFor = function (string $code): array {
        $config = Yaml::parseFile($this->directory.'/xx.yaml');

        foreach ($config['canonical_items'] as $item) {
            if ($item['code'] === $code) {
                return $item['variants'] ?? [];
            }
        }

        return [];
    };
});

afterEach(function (): void {
    array_map('unlink', glob($this->directory.'/corpus/*') ?: []);
    array_map('unlink', glob($this->directory.'/*.yaml') ?: []);
    @rmdir($this->directory.'/corpus');
    @rmdir($this->directory);
});

it('adds corpus wordings to the catalogue as variants', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();

    expect(($this->variantsFor)('rice_1kg'))
        ->toContain('رز مصري')
        ->toContain('ارز حبة قصيرة')
        ->and(($this->variantsFor)('cooking_oil_1l'))
        ->toContain('زيت عباد الشمس');
});

it('keeps the variants the catalogue already had', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();

    expect(($this->variantsFor)('rice_1kg'))->toContain('ارز')->toContain('rice');
});

it('leaves an item the corpus asks it to hold completely alone', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();

    // A corpus says "hold" about an item nobody has verified yet. Promoting it
    // anyway puts a guess in the catalogue, where it is much harder to find.
    expect(($this->variantsFor)('eggs_30'))->toBe(['بيض']);
});

it('refuses a wording that is also listed as a distractor', function (): void {
    // The corpus asserts both that this means cooking oil and that it matches
    // nothing. That contradiction must not be resolved silently in either
    // direction — the wording is simply not promoted.
    ($this->writeCorpus)([
        'items' => ['cooking_oil_1l' => ['زيت زيتون بكر', 'زيت قلي']],
        'distractors' => ['زيت زيتون بكر'],
    ]);

    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])
        ->expectsOutputToContain('also listed as a distractor')
        ->assertSuccessful();

    expect(($this->variantsFor)('cooking_oil_1l'))
        ->toContain('زيت قلي')
        ->not->toContain('زيت زيتون بكر');
});

it('refuses a wording that two different items both claim', function (): void {
    ($this->writeCorpus)([
        'items' => [
            'rice_1kg' => ['كيلو', 'رز مصري'],
            'cooking_oil_1l' => ['كيلو', 'زيت قلي'],
        ],
    ]);

    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])
        ->expectsOutputToContain('ambiguous across items')
        ->assertSuccessful();

    expect(($this->variantsFor)('rice_1kg'))->not->toContain('كيلو')
        ->and(($this->variantsFor)('cooking_oil_1l'))->not->toContain('كيلو');
});

it('promotes nothing the second time it runs', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();
    $after = file_get_contents($this->directory.'/xx.yaml');

    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])
        ->expectsOutputToContain('Nothing to promote')
        ->assertSuccessful();

    expect(file_get_contents($this->directory.'/xx.yaml'))->toBe($after);
});

it('writes nothing at all on a dry run', function (): void {
    $before = file_get_contents($this->directory.'/xx.yaml');

    $this->artisan('qeema:corpus:promote', ['--country' => 'XX', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(file_get_contents($this->directory.'/xx.yaml'))->toBe($before);
});

it('keeps the comments a maintainer wrote', function (): void {
    // Parsing and re-emitting the YAML would strip these, turning a reviewable
    // diff into an unreadable one.
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();

    expect(file_get_contents($this->directory.'/xx.yaml'))
        ->toContain('# A comment a maintainer wrote and would like to keep.');
});

it('produces a file that still parses and still describes the same items', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertSuccessful();

    $config = Yaml::parseFile($this->directory.'/xx.yaml');

    expect(array_column($config['canonical_items'], 'code'))
        ->toBe(['rice_1kg', 'cooking_oil_1l', 'eggs_30'])
        ->and($config['country']['code'])->toBe('XX');
});

it('fails clearly when the country has no corpus', function (): void {
    unlink($this->directory.'/corpus/xx.json');

    $this->artisan('qeema:corpus:promote', ['--country' => 'XX'])->assertFailed();
});

it('fails clearly when the country has no configuration', function (): void {
    $this->artisan('qeema:corpus:promote', ['--country' => 'ZZ'])->assertFailed();
});

it('requires a country', function (): void {
    $this->artisan('qeema:corpus:promote')->assertFailed();
});
