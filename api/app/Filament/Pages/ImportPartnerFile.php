<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\IngestionBatch;
use App\Models\Source;
use App\Support\Ingestion\ColumnMapping;
use App\Support\Ingestion\PartnerFileImporter;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnitEnum;

/**
 * Upload a partner spreadsheet and map its columns.
 *
 * Two steps on purpose. The importer can guess a mapping from the file's own
 * headers, but it confirms with a human before importing: a silently misread
 * column puts a price against the wrong item, and that is far harder to notice
 * afterwards than to prevent here.
 */
final class ImportPartnerFile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Ingestion';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.import-partner-file';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<string> */
    public array $headers = [];

    /** @var list<array<string, string|null>> */
    public array $sample = [];

    public ?string $uploadedPath = null;

    public ?int $lastBatchId = null;

    public function getTitle(): string
    {
        return 'Import partner file';
    }

    public function mount(): void
    {
        // Filament resolves the schema by name at runtime; PHPStan cannot see
        // the dynamically-provided property, so it is reached through the
        // documented accessor instead.
        $this->getSchema('form')?->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('File')
                    ->description('CSV, TSV or XLSX. Rows are validated individually — a file with some bad rows still imports the good ones.')
                    ->schema([
                        Select::make('source_id')
                            ->label('Partner source')
                            ->options(fn (): array => Source::query()
                                ->where('type', Source::TYPE_PARTNER_UPLOAD)
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->native(false),

                        FileUpload::make('file')
                            ->label('Spreadsheet')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'text/tab-separated-values',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(16 * 1024)
                            ->disk('local')
                            ->directory('partner-uploads')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->inspectUpload()),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Read the headers and offer a guessed mapping, without importing.
     */
    public function inspectUpload(): void
    {
        $path = $this->resolveUploadedPath();

        if ($path === null) {
            return;
        }

        try {
            $inspection = (new PartnerFileImporter)->inspect($path);
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not read that file')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->uploadedPath = $path;
        $this->headers = $inspection['headers'];
        $this->sample = $inspection['sample'];

        // Pre-fill the guess so the common case is one confirming click, while
        // still showing the operator what it decided.
        foreach ($inspection['mapping']->toArray() as $field => $column) {
            $this->data['mapping'][$field] = $column;
        }
    }

    public function import(): void
    {
        $sourceId = $this->data['source_id'] ?? null;
        $path = $this->uploadedPath ?? $this->resolveUploadedPath();

        if ($sourceId === null || $path === null) {
            Notification::make()->title('Choose a source and a file first')->warning()->send();

            return;
        }

        /** @var array<string, mixed> $mappingInput */
        $mappingInput = $this->data['mapping'] ?? [];
        $mapping = ColumnMapping::fromArray($mappingInput);

        if (! $mapping->isComplete()) {
            Notification::make()
                ->title('Mapping incomplete')
                ->body('Still to map: '.implode(', ', $mapping->missingRequired()))
                ->warning()
                ->send();

            return;
        }

        $batch = (new PartnerFileImporter)->import(
            source: Source::query()->findOrFail($sourceId),
            path: $path,
            mapping: $mapping,
            originalFilename: basename($path),
            userId: auth()->id(),
        );

        $this->lastBatchId = $batch->id;

        $this->notifyResult($batch);
    }

    private function notifyResult(IngestionBatch $batch): void
    {
        if ($batch->status === IngestionBatch::STATUS_FAILED) {
            Notification::make()
                ->title('Import failed')
                ->body($batch->error_report['fatal'] ?? 'The file could not be processed.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        // Partial success is reported as success with a caveat, not as a
        // failure: the accepted rows are in, and saying otherwise would push
        // the operator to re-upload and worry about duplicates.
        $body = sprintf(
            '%d of %d rows imported.%s',
            $batch->accepted_count,
            $batch->row_count,
            $batch->rejected_count > 0
                ? sprintf(' %d rejected — see the batch for the per-row report.', $batch->rejected_count)
                : '',
        );

        Notification::make()
            ->title($batch->rejected_count > 0 ? 'Imported with errors' : 'Imported')
            ->body($body)
            ->status($batch->rejected_count > 0 ? 'warning' : 'success')
            ->persistent()
            ->send();
    }

    private function resolveUploadedPath(): ?string
    {
        $file = $this->data['file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        if (! is_string($file) || $file === '') {
            return null;
        }

        $path = Storage::disk('local')->path($file);

        return is_file($path) ? $path : null;
    }
}
