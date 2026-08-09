<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $config
 */
final class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    public const TYPE_REPORTER = 'reporter';

    public const TYPE_PARTNER_UPLOAD = 'partner_upload';

    public const TYPE_SCRAPER = 'scraper';

    protected $fillable = [
        'country_id', 'type', 'name', 'slug', 'url', 'license', 'contact',
        'config', 'last_run_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasMany<IngestionBatch, $this> */
    public function ingestionBatches(): HasMany
    {
        return $this->hasMany(IngestionBatch::class);
    }

    /**
     * Where a resumable scraper left off.
     *
     * Kept on the source rather than in memory so an interrupted run resumes
     * instead of restarting, which matters when the remote end is rate-limited.
     */
    public function resumeCursor(): ?string
    {
        /** @var array<string, mixed> $config */
        $config = $this->config ?? [];

        $cursor = $config['cursor'] ?? null;

        return is_string($cursor) ? $cursor : null;
    }

    public function setResumeCursor(?string $cursor): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->config ?? [];
        $config['cursor'] = $cursor;

        $this->config = $config;
        $this->save();
    }
}
