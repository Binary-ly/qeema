<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ingestion;

use App\Models\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Validates one row of a partner file.
 *
 * Every failure is returned rather than thrown. A partner spreadsheet with 900
 * good rows and 100 bad ones must import 900 rows and hand back a list of the
 * 100 — rejecting the file wholesale means the partner has to find and fix
 * everything before Qeema gets any of it, which in practice means Qeema never
 * gets any of it.
 */
final class RowValidator
{
    /**
     * @param  Collection<string, int>  $locationIdsBySlug
     * @param  Collection<string, string>  $locationSlugsByName  lowercased name => slug
     * @param  list<string>  $unitCodes  lowercased unit codes
     */
    public function __construct(
        private readonly Country $country,
        private readonly Collection $locationIdsBySlug,
        private readonly Collection $locationSlugsByName,
        private readonly array $unitCodes,
    ) {}

    /**
     * @param  array<string, string|null>  $row  keyed by partner column header
     */
    public function validate(int $rowNumber, array $row, ColumnMapping $mapping): RowResult
    {
        $errors = [];

        $itemText = $mapping->value($row, 'item');
        if ($itemText === null) {
            $errors[] = $this->error($rowNumber, $mapping->column('item'), 'Item is missing.');
        }

        $priceRaw = $mapping->value($row, 'price');
        $price = $this->parsePrice($priceRaw);
        if ($price === null) {
            // "not a number" would be a lie for the ambiguous case, and a partner
            // told their perfectly good number is not a number has no idea what
            // to change. Say which two readings are in play and let them pick.
            $errors[] = $this->error(
                $rowNumber,
                $mapping->column('price'),
                $priceRaw !== null && $this->hasAmbiguousSeparator($this->numericSkeleton($priceRaw))
                    ? sprintf(
                        'Price "%s" could mean either %s or %s in %s, which has three decimal places. '
                        .'Write it without a thousands separator, or with a dot as the decimal mark.',
                        $priceRaw,
                        str_replace(',', '', $this->numericSkeleton($priceRaw)),
                        str_replace(',', '.', $this->numericSkeleton($priceRaw)),
                        $this->country->currency_code,
                    )
                    : sprintf('Price "%s" is not a number.', (string) $priceRaw),
            );
        } elseif ($price <= 0.0) {
            $errors[] = $this->error($rowNumber, $mapping->column('price'), 'Price must be greater than zero.');
        }

        $locationRaw = $mapping->value($row, 'location');
        $locationId = $locationRaw === null ? null : $this->resolveLocation($locationRaw);

        if ($locationRaw === null) {
            $errors[] = $this->error($rowNumber, $mapping->column('location'), 'Location is missing.');
        } elseif ($locationId === null) {
            // Named explicitly, because "unknown location" without the value is
            // useless to whoever has to fix the file.
            $errors[] = $this->error(
                $rowNumber,
                $mapping->column('location'),
                sprintf('Location "%s" does not match any configured location.', $locationRaw),
            );
        }

        $unit = $mapping->value($row, 'unit');
        if ($unit !== null && ! in_array(mb_strtolower($unit), $this->unitCodes, true)) {
            $errors[] = $this->error(
                $rowNumber,
                $mapping->column('unit'),
                sprintf('Unit "%s" is not configured for this country.', $unit),
            );
        }

        $quantity = $this->parsePrice($mapping->value($row, 'quantity'));
        if ($mapping->value($row, 'quantity') !== null && ($quantity === null || $quantity <= 0.0)) {
            $errors[] = $this->error($rowNumber, $mapping->column('quantity'), 'Quantity must be a positive number.');
        }

        $observedAt = $this->parseDate($mapping->value($row, 'observed_at'));
        if ($mapping->value($row, 'observed_at') !== null && $observedAt === null) {
            $errors[] = $this->error(
                $rowNumber,
                $mapping->column('observed_at'),
                sprintf('Date "%s" could not be read.', (string) $mapping->value($row, 'observed_at')),
            );
        } elseif ($observedAt !== null && $observedAt->isAfter(CarbonImmutable::now()->addDay())) {
            $errors[] = $this->error($rowNumber, $mapping->column('observed_at'), 'Date is in the future.');
        }

        if ($errors !== []) {
            return RowResult::invalid($rowNumber, $errors);
        }

        return RowResult::valid($rowNumber, [
            'item_text' => $itemText,
            'price' => $price,
            'location_id' => $locationId,
            'currency' => mb_strtoupper($mapping->value($row, 'currency') ?? $this->country->currency_code),
            'unit' => $unit !== null ? mb_strtolower($unit) : null,
            'quantity' => $quantity,
            'observed_at' => $observedAt ?? CarbonImmutable::now(),
            'external_id' => $mapping->value($row, 'external_id'),
        ]);
    }

    /**
     * Match a partner's location string to a configured location.
     *
     * Partners write the English name, a lowercased form, or the local-language
     * name. Matching
     * on slug and on either name form covers those without demanding the
     * partner adopt Qeema's identifiers.
     */
    private function resolveLocation(string $raw): ?int
    {
        $key = mb_strtolower(trim($raw), 'UTF-8');

        $slug = $this->locationSlugsByName->get($key);

        if ($slug !== null) {
            return $this->locationIdsBySlug->get($slug);
        }

        return $this->locationIdsBySlug->get($key);
    }

    /**
     * Reduce a written number to digits, separators and sign.
     *
     * Arabic-Indic and Eastern Arabic-Indic digits fold to ASCII; currency
     * symbols and spaces go. What survives still has to be interpreted.
     */
    private function numericSkeleton(string $value): string
    {
        $clean = $value;

        for ($i = 0; $i <= 9; $i++) {
            $clean = str_replace([mb_chr(0x0660 + $i, 'UTF-8'), mb_chr(0x06F0 + $i, 'UTF-8')], (string) $i, $clean);
        }

        return (string) preg_replace('/[^\d.,\-]/u', '', $clean);
    }

    /**
     * True when a lone comma before three digits could be either mark.
     *
     * "1,250" is a thousands separator nearly everywhere, and in a currency with
     * three minor units it is not: twenty of that currency is written "20,000".
     * Retailers really do render whole catalogues that way — two independent
     * sweeps of shop listings found the convention, and one storefront's own
     * "1 - 200" price filter settles how its numbers are meant to be read.
     *
     * So the string has two readings a thousand apart and nothing in it decides
     * between them. Guessing "group" turns twenty into twenty thousand, which is
     * how a basket cost three orders of magnitude too large once reached the
     * dashboard. Guessing "decimal" turns a genuine 1,250 into 1.25 — the same
     * error pointing the other way.
     *
     * A row this class cannot read is a row it hands back, so the partner — who
     * knows what they meant — resolves it. That is the whole design: return the
     * bad rows with a useful message rather than decide on their behalf.
     *
     * Only a *lone* comma is ambiguous. "1,234,500" is grouped beyond doubt, and
     * anything carrying a dot as well is settled by which mark comes last.
     */
    private function hasAmbiguousSeparator(string $skeleton): bool
    {
        return ($this->country->currency_minor_units ?? 2) === 3
            && substr_count($skeleton, ',') === 1
            && ! str_contains($skeleton, '.')
            && preg_match('/,\d{3}$/', $skeleton) === 1;
    }

    /**
     * Parse a number as a partner might have written it.
     *
     * Handles thousands separators, a comma decimal mark, currency symbols and
     * Arabic-Indic digits — all of which appear in real files and none of which
     * mean the row is wrong. Returns null when the row genuinely cannot be read,
     * including the ambiguous case above.
     */
    private function parsePrice(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = $this->numericSkeleton($value);

        if ($clean === '' || $clean === '-') {
            return null;
        }

        if ($this->hasAmbiguousSeparator($clean)) {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Whichever appears last is the decimal mark; the other groups.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $group = $decimal === ',' ? '.' : ',';
            $clean = str_replace($group, '', $clean);
            $clean = str_replace($decimal, '.', $clean);
        } elseif ($lastComma !== false) {
            // A lone comma with exactly three trailing digits is a thousands
            // separator ("1,250"); otherwise it is a decimal mark ("12,50").
            // In a three-decimal currency the first case never reaches here.
            $clean = preg_match('/,\d{3}$/', $clean) === 1
                ? str_replace(',', '', $clean)
                : str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // Excel serial dates arrive as bare numbers when a column was formatted
        // as General; reading one as a year would silently misdate the row.
        if (preg_match('/^\d{5}$/', $value) === 1) {
            return CarbonImmutable::create(1899, 12, 30)?->addDays((int) $value);
        }

        // Carbon throws InvalidFormatException on a mismatch rather than
        // returning false, so each attempt is guarded individually. Without
        // this the first non-matching format escapes and fails the entire
        // batch — one odd date killing a partner's whole upload.
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value)->startOfDay();
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{row: int, column: string|null, message: string}
     */
    private function error(int $row, ?string $column, string $message): array
    {
        return ['row' => $row, 'column' => $column, 'message' => $message];
    }
}
