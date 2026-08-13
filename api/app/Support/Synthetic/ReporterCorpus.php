<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Synthetic;

/**
 * Wordings real people use, as a committed data file rather than a rule.
 *
 * `RawTextGenerator` mutates catalogue names: it reintroduces hamza, switches
 * digits to Arabic-Indic form, inserts typos. Those are precisely the
 * transformations the matcher's normaliser undoes, and both were written from
 * the same list. So a matching score measured against that text is partly a
 * measure of whether the normaliser was implemented correctly, and only partly
 * a measure of whether it survives how people actually write.
 *
 * This corpus exists to break that circularity. Its phrasings were not derived
 * from the catalogue by any rule the matcher knows — they are brand names,
 * colloquial terms, abbreviations and misspellings drawn from usage. Failures
 * against them are informative in a way failures against a rule-mutated
 * catalogue name structurally cannot be.
 *
 * **It is still synthetic.** It was authored by a language model, so its realism
 * is asserted rather than measured, and nothing here converts a figure measured
 * against it into a figure measured against a market. It is a harder test, not
 * a real one.
 *
 * Held as data under `countries/corpus/` for two reasons. Generating it at run
 * time would put a hosted model in the runtime path, which C1 forbids and which
 * would stop `docker compose up` working on a clean machine (C2). And as a file
 * it can be read, corrected by somebody who speaks the language, and diffed.
 */
final class ReporterCorpus
{
    /**
     * @param  array<string, list<string>>  $phrasings  item code => wordings
     * @param  list<string>  $prefixes
     * @param  list<string>  $suffixes
     * @param  list<array<string, mixed>>  $locations
     */
    public function __construct(
        private readonly array $phrasings = [],
        private readonly array $prefixes = [],
        private readonly array $suffixes = [],
        private readonly array $locations = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Load the corpus for a country, or an empty one if it has none.
     *
     * Absence is normal and silent: a country without a corpus generates
     * exactly as it did before this existed, which is what keeps the shipped
     * demo unchanged.
     */
    public static function forCountry(string $countryCode, ?string $directory = null): self
    {
        $directory ??= (string) config('qeema.countries_path').'/corpus';
        $path = $directory.'/'.strtolower($countryCode).'.json';

        if (! is_file($path)) {
            return self::empty();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return self::empty();
        }

        return new self(
            // Normalised on the way in, so the declared type is true rather
            // than defended against at every read. The file is data somebody
            // may hand-edit, so it is not assumed to be well-formed.
            phrasings: self::phrasingMap($decoded['items'] ?? null),
            prefixes: self::strings($decoded['prefixes'] ?? []),
            suffixes: self::strings($decoded['suffixes'] ?? []),
            locations: is_array($decoded['locations'] ?? null) ? array_values($decoded['locations']) : [],
        );
    }

    /**
     * @param  mixed  $value
     * @return array<string, list<string>>
     */
    private static function phrasingMap($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $code => $phrasings) {
            $cleaned = self::strings($phrasings);

            if (is_string($code) && $cleaned !== []) {
                $map[$code] = $cleaned;
            }
        }

        return $map;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function strings($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    public function isEmpty(): bool
    {
        return $this->phrasings === [];
    }

    /** @return list<string> */
    public function phrasingsFor(string $itemCode): array
    {
        return $this->phrasings[$itemCode] ?? [];
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /** @return list<string> */
    public function suffixes(): array
    {
        return $this->suffixes;
    }

    /** @return list<array<string, mixed>> */
    public function locations(): array
    {
        return $this->locations;
    }

    public function phrasingCount(): int
    {
        return array_sum(array_map('count', $this->phrasings));
    }
}
