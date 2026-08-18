<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reporter;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete personal data the deployment no longer has a reason to hold.
 *
 * **Why a retention policy is not optional.** Data minimisation is not only
 * about what you collect; it is about how long you keep it. A platform that
 * collects little but keeps it forever accumulates exactly the archive it was
 * designed to avoid — and the risk grows with time, because a photograph taken
 * in a market is no less identifying in three years and considerably harder to
 * justify holding.
 *
 * **Why it deletes nothing by default.** Both windows are zero out of the box,
 * which means disabled. A retention job that starts deleting the moment an
 * operator upgrades is a data-loss incident wearing a privacy costume, and the
 * right period is a judgement only the operator can make — it depends on their
 * legal basis, their partners and what they told reporters. So this ships as a
 * working mechanism awaiting a policy, not a policy imposed on a deployment.
 *
 * **What it will not delete.** Price observations, index snapshots and the
 * published figures computed from them, ever. Those are anonymous aggregates
 * that other people's decisions rest on, and expiring them would silently
 * rewrite history for every consumer of the index. Retention here removes the
 * personal residue around the prices, never the prices.
 *
 * Two independent windows, because the artefacts carry very different risk:
 *
 * - **Photographs** are the highest-risk thing in the system by a wide margin.
 *   Metadata is stripped on ingest, but a picture can still show a face, a
 *   shopfront or a licence plate, and no software decides how long that should
 *   be kept. They are also the least load-bearing: a photograph corroborates a
 *   price that has long since been screened, resolved and published.
 * - **Dormant reporters** are a smaller risk and a larger loss. Erasing one
 *   destroys a reputation built over months, and somebody who reports each
 *   harvest is not dormant at six months. Set this window long or leave it off.
 *
 * @see ForgetReporterCommand for erasure on request,
 *      which is a different obligation and works whatever this is set to.
 */
final class EnforceRetentionCommand extends Command
{
    /** Rows to load at once when deleting photographs. */
    private const CHUNK = 500;

    protected $signature = 'qeema:retention:enforce
                            {--dry-run : Report what would be deleted and change nothing}';

    protected $description = 'Delete personal data past its configured retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $photoDays = (int) config('qeema.privacy.photo_retention_days');
        $reporterDays = (int) config('qeema.privacy.dormant_reporter_retention_days');

        if ($photoDays <= 0 && $reporterDays <= 0) {
            $this->line('Retention is not configured; nothing to enforce.');
            $this->line('  Set QEEMA_PHOTO_RETENTION_DAYS and/or QEEMA_DORMANT_REPORTER_RETENTION_DAYS.');
            $this->line('  See docs/privacy.md before choosing a period.');

            return self::SUCCESS;
        }

        if ($photoDays > 0) {
            $this->expirePhotographs($photoDays, $dryRun);
        }

        if ($reporterDays > 0) {
            $this->expireDormantReporters($reporterDays, $dryRun);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing changed.');
        }

        return self::SUCCESS;
    }

    /**
     * Remove photographs older than the window, from disk and from the row.
     *
     * The submission itself survives with `photo_path` null. The price is the
     * contribution; the picture was corroboration for a screening decision that
     * was made long ago.
     */
    private function expirePhotographs(int $days, bool $dryRun): void
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        $expired = Submission::query()
            ->whereNotNull('photo_path')
            ->where('created_at', '<', $cutoff);

        $total = (clone $expired)->count();

        $this->line(sprintf(
            'Photographs older than %d days (before %s): %d',
            $days,
            $cutoff->toDateString(),
            $total,
        ));

        if ($total === 0 || $dryRun) {
            return;
        }

        $deleted = 0;

        // Chunked by id rather than loaded at once: a deployment that turns
        // this on for the first time after a year of collection has every
        // photograph it has ever stored in this set.
        (clone $expired)->chunkById(self::CHUNK, function ($submissions) use (&$deleted): void {
            foreach ($submissions as $submission) {
                // The file first. A row still pointing at a deleted file is
                // findable; a file on disk with nothing pointing at it is not.
                if (Storage::disk('local')->delete($submission->photo_path)) {
                    $deleted++;
                }

                $submission->forceFill(['photo_path' => null])->save();
            }
        });

        $this->info("  {$deleted} photograph(s) deleted from disk.");
    }

    /**
     * Erase reporters who have not submitted anything for the whole window.
     *
     * `last_seen_at` null is treated as never seen and falls back to when the
     * row was created, so a reporter row created by an import that never
     * reported does not live forever on a null.
     */
    private function expireDormantReporters(int $days, bool $dryRun): void
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        $dormant = Reporter::query()->whereRaw(
            'COALESCE(last_seen_at, created_at) < ?',
            [$cutoff],
        );

        $total = (clone $dormant)->count();

        $this->line(sprintf(
            'Reporters dormant for more than %d days (since before %s): %d',
            $days,
            $cutoff->toDateString(),
            $total,
        ));

        if ($total === 0 || $dryRun) {
            return;
        }

        // Deleting the row is what unlinks their submissions: `reporter_id` is
        // nullable with `nullOnDelete`, so every price survives with nothing
        // pointing back to a person. Same semantics as erasure on request,
        // deliberately — there should be one meaning of "forget a reporter".
        $erased = 0;

        (clone $dormant)->chunkById(self::CHUNK, function ($reporters) use (&$erased): void {
            foreach ($reporters as $reporter) {
                $reporter->delete();
                $erased++;
            }
        });

        $this->info("  {$erased} dormant reporter(s) erased; their prices kept.");
    }
}
