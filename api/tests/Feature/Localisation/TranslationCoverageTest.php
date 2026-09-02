<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
 * Every locale carries every key.
 *
 * Laravel falls back to the fallback locale for a missing key, so an untranslated
 * string does not raise anything — it renders in English inside an otherwise
 * Spanish or Arabic page, which reads as a bug in the data rather than a gap in
 * the translation. Two keys added to the dashboard reached the second country's
 * language file only because this test was written; nothing else would have
 * reported them.
 *
 * English is the reference because it is the fallback: a key that exists only in
 * another locale is unreachable for anyone else, and is reported too.
 */

/**
 * @param  array<string, mixed>  $lines
 * @return list<string>
 */
function flatKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = [...$keys, ...flatKeys($value, $dotted)];

            continue;
        }

        $keys[] = $dotted;
    }

    return $keys;
}

it('translates every key into every locale', function (): void {
    $reference = 'en';
    $gaps = [];

    /** @var list<string> $groups */
    $groups = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(lang_path($reference).'/*.php') ?: [],
    );

    expect($groups)->not->toBeEmpty();

    foreach (glob(lang_path('*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $locale = basename($directory);

        if ($locale === $reference) {
            continue;
        }

        foreach ($groups as $group) {
            $referenceFile = lang_path($reference)."/{$group}.php";
            $localeFile = "{$directory}/{$group}.php";

            if (! file_exists($localeFile)) {
                $gaps[] = "{$locale}: {$group}.php is missing entirely";

                continue;
            }

            /** @var array<string, mixed> $referenceLines */
            $referenceLines = require $referenceFile;
            /** @var array<string, mixed> $localeLines */
            $localeLines = require $localeFile;

            $referenceKeys = flatKeys($referenceLines);
            $localeKeys = flatKeys($localeLines);

            foreach (array_diff($referenceKeys, $localeKeys) as $missing) {
                $gaps[] = "{$locale}: {$group}.{$missing} is untranslated";
            }

            foreach (array_diff($localeKeys, $referenceKeys) as $orphan) {
                $gaps[] = "{$locale}: {$group}.{$orphan} exists in no other locale";
            }
        }
    }

    expect($gaps)->toBe([]);
});
