<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ingestion;

/**
 * The outcome of validating one spreadsheet row.
 *
 * @phpstan-type RowError array{row: int, column: string|null, message: string}
 */
final readonly class RowResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<RowError>  $errors
     */
    private function __construct(
        public int $rowNumber,
        public bool $isValid,
        public ?array $data,
        public array $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function valid(int $rowNumber, array $data): self
    {
        return new self($rowNumber, true, $data, []);
    }

    /**
     * @param  list<RowError>  $errors
     */
    public static function invalid(int $rowNumber, array $errors): self
    {
        return new self($rowNumber, false, null, $errors);
    }
}
