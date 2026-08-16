<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Synthetic;

use Random\Randomizer;

/**
 * Produces the messy free text a real reporter would actually type.
 *
 * This exists because a matcher evaluated on clean catalogue names learns
 * nothing. Real submissions arrive with hamza dropped, Arabic-Indic digits,
 * dialect words, brand names in Latin script inside Arabic phrases, missing
 * spaces and ordinary typos. The synthetic corpus has to contain all of that or
 * the Phase 5 accuracy figures are meaningless.
 */
final class RawTextGenerator
{
    /** Filler a reporter might add that carries no product information. */
    private const NOISE_PREFIXES = ['', '', '', 'سعر ', 'ثمن ', 'price ', ''];

    private const NOISE_SUFFIXES = ['', '', '', ' اليوم', ' بالسوق', ' محلي', ' imported', ''];

    /**
     * How often a wording is taken from the corpus rather than from the
     * catalogue, when a corpus exists.
     *
     * Not 1.0 on purpose. Some people do type something close to the catalogue
     * name, and a corpus-only stream would be its own kind of unrealistic — the
     * mirror image of the problem it exists to fix.
     */
    private const CORPUS_SHARE = 0.75;

    public function __construct(
        private readonly Randomizer $randomizer,
        private readonly ReporterCorpus $corpus = new ReporterCorpus,
    ) {}

    /**
     * Generate one submission's raw text for an item.
     *
     * @param  list<string>  $variants  known spellings from the country config
     * @param  string  $itemCode  used to look up corpus wordings; optional so
     *                            existing callers and the shipped demo are
     *                            unaffected
     */
    public function generate(string $canonicalName, array $variants, string $itemCode = ''): string
    {
        $catalogue = array_values(array_filter([$canonicalName, ...$variants]));
        $weighted = $itemCode === '' ? [] : $this->corpus->weightedPhrasingsFor($itemCode);

        // The catalogue pool is what the matcher already knows: these are the
        // variants indexed for lexical search, so a submission drawn from them
        // is close to a gimme. The corpus pool is not — nothing in it was
        // derived from the catalogue by a rule the matcher shares.
        if ($weighted !== [] && $this->chance(self::CORPUS_SHARE)) {
            // Sampled by weight rather than uniformly, so the handful of
            // wordings that dominate real traffic dominate here too.
            $text = $this->pickWeighted($weighted);
        } elseif ($catalogue !== []) {
            $text = $catalogue[$this->randomizer->getInt(0, count($catalogue) - 1)];
        } else {
            return '';
        }

        // Reintroduce hamza on a bare alef: reporters type both ways, and the
        // normaliser has to fold them back together.
        if ($this->chance(0.25)) {
            $text = $this->reintroduceHamza($text);
        }

        // Write digits in Arabic-Indic form.
        if ($this->chance(0.30)) {
            $text = $this->toArabicIndicDigits($text);
        }

        if ($this->chance(0.15)) {
            $text = $this->introduceTypo($text);
        }

        // A reporter writes "سعر دحي", never "سعردحي". Word boundaries belong to
        // the generator rather than to the corpus: affixes were previously
        // concatenated raw, and one corpus happened to bake its own spaces in
        // ("el ", " en el abasto") while the other did not — so the second
        // corpus produced a glued prefix on about one line in ten. Trimming
        // here makes both behave the same and leaves neither with a double
        // space.
        $carriesUnit = $this->carriesUnit($text);

        if ($this->chance(0.20)) {
            $prefix = trim($this->pick($this->prefixPool()));

            // "كيلو" in front of "طبق دحي ٣٠" is a kilo of a tray of eggs. A
            // reporter states a unit once, so the second one is not a hard case
            // for the matcher, it is a line no human wrote.
            if ($prefix !== '' && ! ($carriesUnit && $this->carriesUnit($prefix))) {
                $text = $prefix.' '.$text;
                $carriesUnit = $carriesUnit || $this->carriesUnit($prefix);
            }
        }

        if ($this->chance(0.20)) {
            $suffix = trim($this->pick($this->suffixPool()));

            if ($suffix !== '' && ! ($carriesUnit && $this->carriesUnit($suffix))) {
                $text .= ' '.$suffix;
            }
        }

        // Doubled or missing spaces.
        if ($this->chance(0.10)) {
            $text = str_replace(' ', '  ', $text);
        }

        return trim($text);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $weighted
     */
    private function pickWeighted(array $weighted): string
    {
        $total = 0;

        foreach ($weighted as [, $weight]) {
            $total += $weight;
        }

        $roll = $this->randomizer->getInt(1, max(1, $total));

        foreach ($weighted as [$phrasing, $weight]) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $phrasing;
            }
        }

        return $weighted[array_key_last($weighted)][0];
    }

    /**
     * Does this text already state a unit or a pack size?
     *
     * The words are declared by the corpus rather than known here, because
     * "kilo" and "كيلو" and "طبق" are country facts and this file must not hold
     * any (C3). A corpus that declares none simply gets the old behaviour.
     */
    private function carriesUnit(string $text): bool
    {
        foreach ($this->corpus->unitWords() as $unit) {
            if ($unit !== '' && mb_stripos($text, $unit) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function prefixPool(): array
    {
        $fromCorpus = $this->corpus->prefixes();

        return $fromCorpus === [] ? self::NOISE_PREFIXES : $fromCorpus;
    }

    /**
     * @return list<string>
     */
    private function suffixPool(): array
    {
        $fromCorpus = $this->corpus->suffixes();

        return $fromCorpus === [] ? self::NOISE_SUFFIXES : $fromCorpus;
    }

    /** Replace a bare alef with a hamza-carrying form. */
    private function reintroduceHamza(string $text): string
    {
        $position = mb_strpos($text, "\u{0627}");

        if ($position === false) {
            return $text;
        }

        $replacement = $this->chance(0.7) ? "\u{0623}" : "\u{0625}";

        return mb_substr($text, 0, $position).$replacement.mb_substr($text, $position + 1);
    }

    private function toArabicIndicDigits(string $text): string
    {
        $map = [];
        for ($i = 0; $i <= 9; $i++) {
            $map[(string) $i] = mb_chr(0x0660 + $i, 'UTF-8');
        }

        return strtr($text, $map);
    }

    /**
     * A single-character transposition, deletion or duplication.
     *
     * Deliberately mild: a typo severe enough to be unrecognisable to a human
     * would just be unlabelled noise, and would make the accuracy ceiling
     * artificially low rather than realistic.
     */
    private function introduceTypo(string $text): string
    {
        $length = mb_strlen($text);

        if ($length < 4) {
            return $text;
        }

        $i = $this->randomizer->getInt(1, $length - 2);
        $head = mb_substr($text, 0, $i);
        $char = mb_substr($text, $i, 1);
        $tail = mb_substr($text, $i + 1);

        return match ($this->randomizer->getInt(1, 3)) {
            1 => $head.$tail,                                  // deletion
            2 => $head.$char.$char.$tail,                       // duplication
            default => $head.mb_substr($text, $i + 1, 1).$char.mb_substr($text, $i + 2), // transposition
        };
    }

    /**
     * A unit string as a reporter might write it, which is often not the unit
     * the catalogue expects.
     */
    public function unitText(string $unitCode): string
    {
        $options = match ($unitCode) {
            'kg' => ['kg', 'كيلو', 'كجم', 'kilo', 'كيلوغرام'],
            'g' => ['g', 'غرام', 'جرام', 'gram'],
            'l' => ['l', 'لتر', 'litre', 'liter'],
            'ml' => ['ml', 'مل', 'مليلتر'],
            'piece' => ['piece', 'قطعة', 'حبة', 'وحدة'],
            'pack' => ['pack', 'علبة', 'عبوة', 'كرتونة'],
            'dozen' => ['dozen', 'دزينة'],
            default => [$unitCode],
        };

        return $options[$this->randomizer->getInt(0, count($options) - 1)];
    }

    private function chance(float $probability): bool
    {
        return $this->randomizer->getFloat(0.0, 1.0) < $probability;
    }

    /**
     * @param  list<string>  $options
     */
    private function pick(array $options): string
    {
        return $options[$this->randomizer->getInt(0, count($options) - 1)];
    }
}
