<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ingestion;

/**
 * Maps a partner's spreadsheet columns onto Qeema's fields.
 *
 * Partners send whatever their own systems produce — "Product", "Item name",
 * "السلعة", "commodity" — and asking each of them to reformat before uploading
 * is how a data-sharing arrangement quietly dies. The mapping is therefore data,
 * chosen once per source and stored on the batch, so a repeat upload from the
 * same partner needs no human at all.
 */
final readonly class ColumnMapping
{
    /** Fields a row must supply for it to become a submission. */
    public const REQUIRED = ['item', 'price', 'location'];

    public const OPTIONAL = ['unit', 'quantity', 'observed_at', 'currency', 'external_id'];

    /**
     * @param  array<string, string>  $map  qeema field => partner column header
     */
    public function __construct(public array $map) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $map = [];

        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $field) {
            $column = $raw[$field] ?? null;

            if (is_string($column) && trim($column) !== '') {
                $map[$field] = trim($column);
            }
        }

        return new self($map);
    }

    /**
     * Guess a mapping from the file's own headers.
     *
     * A best-effort convenience only — the operator always confirms it before
     * import. Guessing silently and importing anyway is how a column gets
     * misread and a price lands against the wrong item.
     *
     * @param  list<string>  $headers
     */
    public static function guess(array $headers): self
    {
        $candidates = [
            'item' => ['item', 'product', 'commodity', 'item_name', 'product_name', 'goods', 'السلعة', 'المنتج'],
            'price' => ['price', 'unit_price', 'cost', 'amount', 'value', 'السعر'],
            'location' => ['location', 'market', 'town', 'city', 'admin1', 'district', 'الموقع', 'المدينة'],
            'unit' => ['unit', 'uom', 'measure', 'الوحدة'],
            'quantity' => ['quantity', 'qty', 'pack_size', 'size', 'الكمية'],
            'observed_at' => ['date', 'observed_at', 'observed', 'survey_date', 'التاريخ'],
            'currency' => ['currency', 'ccy', 'العملة'],
            'external_id' => ['id', 'external_id', 'record_id', 'reference'],
        ];

        $normalised = [];
        foreach ($headers as $header) {
            $normalised[self::normaliseHeader($header)] = $header;
        }

        $map = [];
        foreach ($candidates as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = self::normaliseHeader($alias);

                if (isset($normalised[$key])) {
                    $map[$field] = $normalised[$key];

                    break;
                }
            }
        }

        return new self($map);
    }

    private static function normaliseHeader(string $header): string
    {
        $lower = mb_strtolower(trim($header), 'UTF-8');

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '_', $lower);
    }

    /**
     * Fields required but not mapped.
     *
     * @return list<string>
     */
    public function missingRequired(): array
    {
        return array_values(array_diff(self::REQUIRED, array_keys($this->map)));
    }

    public function isComplete(): bool
    {
        return $this->missingRequired() === [];
    }

    public function column(string $field): ?string
    {
        return $this->map[$field] ?? null;
    }

    /**
     * Pull a field out of one parsed row.
     *
     * @param  array<string, string|null>  $row  keyed by partner column header
     */
    public function value(array $row, string $field): ?string
    {
        $column = $this->column($field);

        if ($column === null) {
            return null;
        }

        $value = $row[$column] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}
