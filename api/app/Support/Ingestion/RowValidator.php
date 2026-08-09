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

        $price = $this->parsePrice($mapping->value($row, 'price'));
        if ($price === null) {
            $errors[] = $this->error(
                $rowNumber,
                $mapping->column('price'),
                sprintf('Price "%s" is not a number.', (string) $mapping->value($row, 'price')),
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
     * Parse a number as a partner might have written it.
     *
     * Handles thousands separators, a comma decimal mark, currency symbols and
     * Arabic-Indic digits — all of which appear in real files and none of which
     * mean the row is wrong.
     */
    private function parsePrice(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = $value;

        for ($i = 0; $i <= 9; $i++) {
            $clean = str_replace([mb_chr(0x0660 + $i, 'UTF-8'), mb_chr(0x06F0 + $i, 'UTF-8')], (string) $i, $clean);
        }

        // Strip everything that is not a digit, separator or sign.
        $clean = (string) preg_replace('/[^\d.,\-]/u', '', $clean);

        if ($clean === '' || $clean === '-') {
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
