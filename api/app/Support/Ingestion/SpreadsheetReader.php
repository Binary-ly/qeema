<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ingestion;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams rows out of a partner CSV or XLSX file.
 *
 * Streaming rather than loading: a partner's annual export can be tens of
 * thousands of rows, and reading it into memory would turn one large upload
 * into an outage. OpenSpout (MIT) reads row by row and keeps memory flat
 * regardless of file size.
 */
final class SpreadsheetReader
{
    /**
     * Hard cap on rows read from one file.
     *
     * The upload endpoint is authenticated and operator-facing, so this is not
     * an abuse control — it is a guard against a malformed file that reports an
     * enormous row count and would otherwise run until something times out.
     */
    private const MAX_ROWS = 250_000;

    /**
     * Read the header row without consuming the whole file.
     *
     * Used to offer a column mapping before committing to an import.
     *
     * @return list<string>
     */
    public function headers(string $path): array
    {
        foreach ($this->rawRows($path) as $row) {
            return array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        return [];
    }

    /**
     * Yield each data row keyed by its column header.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $path): Generator
    {
        $headers = [];
        $rowNumber = 0;

        foreach ($this->rawRows($path) as $raw) {
            $rowNumber++;

            if ($rowNumber === 1) {
                $headers = array_map(static fn ($c): string => trim((string) $c), $raw);

                continue;
            }

            $values = array_map(static fn ($c): ?string => $c === null ? null : trim((string) $c), $raw);

            // Tolerate ragged rows in both directions: spreadsheets routinely
            // have trailing empty cells trimmed off, and a short row is not a
            // reason to reject a file.
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $values[$i] ?? null;
            }

            // Skip rows that are entirely blank — an artefact of how people
            // save spreadsheets, not something a partner should be told about.
            if (array_filter($row, static fn (?string $v): bool => $v !== null && $v !== '') === []) {
                continue;
            }

            // The spreadsheet row number a human would see, so an error report
            // points at the row they can actually open and fix.
            yield $rowNumber => $row;

            if ($rowNumber > self::MAX_ROWS) {
                return;
            }
        }
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function rawRows(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("Upload not found at {$path}.");
        }

        $reader = $this->readerFor($path);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                // Only the first sheet. A partner workbook often carries notes
                // or a legend on later sheets, and importing those would
                // produce a page of confusing errors.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function readerFor(string $path): CsvReader|XlsxReader
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'xlsx') {
            return new XlsxReader;
        }

        if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = $extension === 'tsv' ? "\t" : $this->sniffDelimiter($path);

            return new CsvReader($options);
        }

        throw new RuntimeException(
            "Unsupported file type '.{$extension}'. Upload a .csv, .tsv or .xlsx file."
        );
    }

    /**
     * Guess the delimiter from the header line.
     *
     * Semicolon-separated CSV is what Excel produces in most of Europe and the
     * Middle East, and silently reading it as comma-separated yields a single
     * column and a wall of unhelpful errors.
     */
    private function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $line = fgets($handle, 8192) ?: '';
        fclose($handle);

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
            '|' => substr_count($line, '|'),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }
}
