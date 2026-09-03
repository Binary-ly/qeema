<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IngestionBatch;
use App\Models\Source;
use App\Support\Ingestion\ColumnMapping;
use App\Support\Ingestion\PartnerFileImporter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Import a partner spreadsheet from the command line.
 *
 * The admin page does the same thing with a form, and for a partner sending a
 * file every month that is the right surface. This exists for the other case:
 * an operator with a shell and an openly-licensed dataset they fetched by hand,
 * because the scheduled scraper honours a robots.txt that forbids it.
 *
 * The first such import on the live deployment was done with a one-off
 * `tinker` script. It worked, and it left no record of what it had selected
 * or how it had mapped the columns — which is how a basket item WFP has
 * surveyed monthly since 2017 went unimported for four months while the
 * dashboard reported it as unpriceable. A command prints its mapping, is
 * recorded on the batch, and can be run the same way next month.
 *
 * Nothing here bypasses the importer's own guarantees: a re-sent file is
 * recognised by checksum and reported rather than doubled, and a bad row is
 * listed rather than fatal.
 */
final class ImportPartnerFileCommand extends Command
{
    /** Row errors to print before pointing at the batch record for the rest. */
    private const ERRORS_SHOWN = 20;

    protected $signature = 'qeema:import:file
                            {path : A CSV, TSV or XLSX file readable by this process}
                            {--source= : Slug of the partner_upload source the rows belong to}
                            {--country= : ISO code, when the same slug exists in more than one country}
                            {--map=* : Override a guessed column, as field=Header}
                            {--dry-run : Print the mapping and a sample, write nothing}';

    protected $description = 'Import a partner spreadsheet the way the admin page does, from a shell';

    public function handle(PartnerFileImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $source = $this->resolveSource();

        if ($source === null) {
            return self::FAILURE;
        }

        try {
            $inspection = $importer->inspect($path);
        } catch (Throwable $e) {
            $this->error('Could not read that file: '.$e->getMessage());

            return self::FAILURE;
        }

        $mapping = $this->applyOverrides($inspection['mapping'], $inspection['headers']);

        if ($mapping === null) {
            return self::FAILURE;
        }

        $this->line("Source: {$source->name} ({$source->slug}, {$source->country->code})");
        $this->table(
            ['field', 'column'],
            array_map(
                static fn (string $field, string $column): array => [$field, $column],
                array_keys($mapping->toArray()),
                array_values($mapping->toArray()),
            ),
        );

        if (! $mapping->isComplete()) {
            $this->error(sprintf(
                'Not mapped: %s. Pass --map=%s=<Header> for each.',
                implode(', ', $mapping->missingRequired()),
                $mapping->missingRequired()[0],
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            foreach ($inspection['sample'] as $row) {
                $this->line('  '.implode(' | ', array_map(
                    static fn (?string $v): string => $v ?? '',
                    array_values($row),
                )));
            }
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        // The importer answers a re-sent file with the original batch rather
        // than a new one. Telling those two outcomes apart matters here: an
        // operator who sees "308 accepted" for a file that was accepted last
        // month will assume the rows were written twice, or worse, that they
        // were not written at all.
        $checksum = hash_file('sha256', $path) ?: '';
        $previous = IngestionBatch::query()
            ->where('source_id', $source->id)
            ->where('checksum', $checksum)
            ->value('id');

        $batch = $importer->import($source, $path, $mapping, basename($path));

        if ($previous !== null) {
            $this->warn("This exact file was already imported as batch {$previous}; nothing was written.");

            return self::SUCCESS;
        }

        return $this->report($batch);
    }

    private function resolveSource(): ?Source
    {
        $slug = trim((string) $this->option('source'));

        if ($slug === '') {
            $this->error('--source is required: the slug of a partner_upload source.');
            $this->listSources();

            return null;
        }

        $query = Source::query()
            ->with('country')
            ->where('slug', $slug)
            ->where('type', Source::TYPE_PARTNER_UPLOAD);

        $country = $this->option('country');

        if (is_string($country) && $country !== '') {
            $code = strtoupper($country);
            $query->whereHas('country', static fn (Builder $q) => $q->where('code', $code));
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->error("No partner_upload source with slug \"{$slug}\".");
            $this->listSources();

            return null;
        }

        if ($sources->count() > 1) {
            $this->error("Slug \"{$slug}\" exists in more than one country; pass --country.");

            return null;
        }

        return $sources->first();
    }

    private function listSources(): void
    {
        $rows = Source::query()
            ->with('country')
            ->where('type', Source::TYPE_PARTNER_UPLOAD)
            ->orderBy('slug')
            ->get()
            ->map(static fn (Source $s): array => [$s->country->code, $s->slug, $s->name])
            ->all();

        if ($rows !== []) {
            $this->table(['country', 'slug', 'name'], $rows);
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function applyOverrides(ColumnMapping $guessed, array $headers): ?ColumnMapping
    {
        $map = $guessed->toArray();
        $fields = [...ColumnMapping::REQUIRED, ...ColumnMapping::OPTIONAL];

        /** @var list<string> $overrides */
        $overrides = (array) $this->option('map');

        foreach ($overrides as $override) {
            [$field, $column] = array_pad(explode('=', $override, 2), 2, null);
            $field = trim((string) $field);
            $column = trim((string) $column);

            if (! in_array($field, $fields, true) || $column === '') {
                $this->error(sprintf(
                    'Bad --map "%s": expected field=Header with field one of %s.',
                    $override,
                    implode(', ', $fields),
                ));

                return null;
            }

            if (! in_array($column, $headers, true)) {
                $this->error(sprintf(
                    'Column "%s" is not in the file. Its headers are: %s.',
                    $column,
                    implode(', ', $headers),
                ));

                return null;
            }

            $map[$field] = $column;
        }

        return ColumnMapping::fromArray($map);
    }

    private function report(IngestionBatch $batch): int
    {
        /** @var array<string, mixed> $report */
        $report = $batch->error_report ?? [];

        if ($batch->status === IngestionBatch::STATUS_FAILED) {
            $this->error(sprintf('Batch %d failed: %s', $batch->id, (string) ($report['fatal'] ?? 'unknown error')));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Batch %d: %d rows, %d accepted, %d rejected.',
            $batch->id,
            (int) $batch->row_count,
            (int) $batch->accepted_count,
            (int) $batch->rejected_count,
        ));

        /** @var list<array<string, mixed>> $rows */
        $rows = $report['rows'] ?? [];

        foreach (array_slice($rows, 0, self::ERRORS_SHOWN) as $error) {
            $this->line('  '.implode(' — ', array_map(
                static fn (mixed $v): string => is_scalar($v)
                    ? (string) $v
                    : (json_encode($v, JSON_UNESCAPED_UNICODE) ?: ''),
                $error,
            )));
        }

        if (count($rows) > self::ERRORS_SHOWN) {
            $this->line(sprintf(
                '  … %d more on the batch record%s.',
                count($rows) - self::ERRORS_SHOWN,
                ($report['truncated'] ?? false) === true ? ' (report truncated at 500)' : '',
            ));
        }

        if ((int) $batch->accepted_count > 0) {
            $this->line('Resolution is queued; the pipeline will place the rows as it reaches them.');
        }

        return self::SUCCESS;
    }
}
