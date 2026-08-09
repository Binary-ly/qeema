<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IngestionBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One partner file upload or one scraper run.
 *
 * Partial success is the normal outcome, not an error state: a partner
 * spreadsheet with 900 good rows and 100 bad ones should import 900 rows and
 * hand back a list of the 100, rather than rejecting the file.
 */
/**
 * @property array<string, mixed>|null $error_report
 * @property array<string, mixed>|null $column_mapping
 */
final class IngestionBatch extends Model
{
    /** @use HasFactory<IngestionBatchFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_id', 'uploaded_by_user_id', 'filename', 'checksum',
        'row_count', 'accepted_count', 'rejected_count', 'status',
        'column_mapping', 'error_report', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'column_mapping' => 'array',
            'error_report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function acceptanceRate(): ?float
    {
        return $this->row_count > 0 ? $this->accepted_count / $this->row_count : null;
    }

    public function hasErrors(): bool
    {
        return $this->rejected_count > 0;
    }

    /**
     * Per-row failures, shaped for a downloadable report the partner can act on.
     *
     * @return list<array{row: int, column: string|null, message: string}>
     */
    public function errorRows(): array
    {
        /** @var list<array{row: int, column: string|null, message: string}> $errors */
        $errors = $this->error_report['rows'] ?? [];

        return $errors;
    }
}
