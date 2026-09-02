<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Translation\MessageSelector;

/*
 * Every pluralised string must supply as many forms as its own language can
 * ask for.
 *
 * Laravel picks a segment by index. When the index it computes does not exist,
 * it silently falls back to the *first* segment — so a string written with
 * English's two forms does not fail in another language, it lies in it. The
 * dashboard labelled a figure 103 days old as "one day ago" in Arabic for
 * exactly this reason: Arabic asks for index 3 at 103, only two forms were
 * written, and index 0 came back looking perfectly fluent.
 *
 * This walks the actual language files rather than a list someone maintains,
 * so a new pluralised string is covered the day it is added.
 */

/**
 * @return array<string, array<string, string>> locale => [dotted key => line]
 */
function pluralisedLines(): array
{
    $found = [];

    foreach (glob(lang_path('*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $locale = basename($directory);

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $group = basename($file, '.php');

            /** @var array<string, mixed> $lines */
            $lines = require $file;

            foreach (data_get_flat($lines) as $key => $value) {
                if (is_string($value) && str_contains($value, '|')) {
                    $found[$locale][$group.'.'.$key] = $value;
                }
            }
        }
    }

    return $found;
}

/**
 * @param  array<string, mixed>  $lines
 * @return array<string, mixed>
 */
function data_get_flat(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat += data_get_flat($value, $dotted);

            continue;
        }

        $flat[$dotted] = $value;
    }

    return $flat;
}

/**
 * The highest segment index this locale's plural rules can ask for.
 */
function highestPluralIndex(string $locale): int
{
    $selector = new MessageSelector;
    $method = new ReflectionMethod($selector, 'getPluralIndex');
    $method->setAccessible(true);

    $highest = 0;

    // Far enough to cross every boundary Arabic uses: 0, 1, 2, 3–10, 11–99 and
    // 100 and up, including the wrap at 101 that produced the original defect.
    foreach (range(0, 200) as $number) {
        $highest = max($highest, (int) $method->invoke($selector, $locale, $number));
    }

    return $highest;
}

it('gives every pluralised string enough forms for its language', function (): void {
    $short = [];

    foreach (pluralisedLines() as $locale => $lines) {
        $required = highestPluralIndex($locale) + 1;

        foreach ($lines as $key => $line) {
            // Explicit ranges ("{0} none|[1,*] some") are chosen by value, not
            // by plural index, so the count of segments says nothing.
            if (preg_match('/^\s*[\{\[]/', $line) === 1) {
                continue;
            }

            $forms = count(explode('|', $line));

            if ($forms < $required) {
                $short[] = "{$locale}: {$key} has {$forms}, needs {$required}";
            }
        }
    }

    expect($short)->toBe([]);
});

it('counts a three-digit age correctly in every locale', function (): void {
    foreach (array_keys(pluralisedLines()) as $locale) {
        app()->setLocale($locale);

        $one = trans_choice('dashboard.days_ago', 1, ['count' => 1]);
        $many = trans_choice('dashboard.days_ago', 103, ['count' => 103]);

        // The number itself has to appear, and 103 days must not be worded as
        // one day — the two symptoms the fallback produced.
        expect($many)->toContain('103')
            ->and($many)->not->toBe($one);
    }
});
