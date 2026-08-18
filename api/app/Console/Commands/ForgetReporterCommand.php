<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reporter;
use App\Models\Submission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erase a reporter, and keep the prices they contributed.
 *
 * **Why both halves matter.** A person who has been reporting prices in a
 * crisis economy may need to stop being associated with having done so — they
 * moved, the situation changed, or they simply want out. Without a way to
 * honour that, the platform is asking people to make a permanent disclosure in
 * exchange for a temporary contribution, which is not a fair trade and is not a
 * lawful one under most data-protection regimes.
 *
 * But the prices are not theirs alone. They are already anonymous, they are
 * published, and other people's decisions rest on them: deleting a year of
 * observations because one reporter withdrew would silently rewrite history for
 * every consumer of the index and every figure computed from it. So erasure
 * severs the person from the record rather than destroying the record.
 *
 * **What that means concretely.** The reporter row is deleted. `reporter_id` on
 * their submissions is a nullable foreign key with `nullOnDelete`, so every
 * submission survives with its price, its text and its timestamps, and with
 * nothing pointing back to a person. The device identifier, the display name
 * and the reputation history go with the row. Photographs, which are the one
 * artefact that can show a face or a shopfront regardless of what the database
 * says, are deleted from disk.
 *
 * **What survives, and what that leaves.** The raw text stays, because a
 * published figure has to be traceable to what somebody typed — that is the
 * platform's audit story and removing it would break it. Text is free-form, so
 * a reporter who typed their own name into a price field would leave it behind;
 * `--scrub-text` exists for that case and is deliberately not the default,
 * because it destroys the matcher's evidence for a resolution.
 *
 * Timing and location survive too. A submission still says a price was reported
 * in this town on this afternoon, and a determined observer with independent
 * knowledge of who was where could still draw a line. Erasure here is the
 * removal of the identifier, not a guarantee of unlinkability, and
 * `docs/do-no-harm.md` says so rather than implying more than is true.
 */
final class ForgetReporterCommand extends Command
{
    protected $signature = 'qeema:reporter:forget
                            {--ref= : The reporter external_ref (the UUID their device holds)}
                            {--id= : The reporter database id, if that is what you have}
                            {--scrub-text : Also blank the raw text of their submissions}
                            {--dry-run : Report what would be erased and change nothing}';

    protected $description = 'Erase a reporter while keeping the prices they contributed';

    public function handle(): int
    {
        $reporter = $this->find();

        if ($reporter === null) {
            $this->error('No reporter matched. Pass --ref=<uuid> or --id=<n>.');

            return self::FAILURE;
        }

        $submissions = Submission::query()->where('reporter_id', $reporter->id);
        $total = (clone $submissions)->count();
        $photos = (clone $submissions)->whereNotNull('photo_path')->pluck('photo_path');

        $this->line("Reporter {$reporter->id} ({$reporter->external_ref})");
        $this->line(sprintf('  display name       %s', $reporter->display_name ?? '(none)'));
        $this->line(sprintf('  submissions        %d — kept, unlinked from any person', $total));
        $this->line(sprintf('  photographs        %d — deleted from disk', $photos->count()));
        $this->line(sprintf('  reputation history %s — deleted with the row', $reporter->reputation));

        if ($this->option('scrub-text')) {
            $this->warn('  raw text           blanked on every submission above');
        }

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        // Photographs first, and outside the transaction. A rolled-back
        // transaction cannot put a deleted file back, so the ordering that
        // fails safely is the one where a crash leaves the database still
        // pointing at files that are already gone — an operator can find those.
        // The reverse leaves a face on disk with nothing pointing at it, which
        // nobody will ever find.
        $deleted = 0;

        foreach ($photos as $path) {
            if (Storage::disk('local')->delete($path)) {
                $deleted++;
            }
        }

        DB::transaction(function () use ($reporter, $submissions): void {
            (clone $submissions)->update(array_merge(
                ['photo_path' => null],
                $this->option('scrub-text') ? ['raw_text' => ''] : [],
            ));

            // The foreign key is nullOnDelete, so this is what unlinks every
            // submission. Doing it by deleting the row rather than by nulling
            // the column keeps one statement of what erasure means instead of
            // two that can disagree.
            $reporter->delete();
        });

        $this->newLine();
        $this->info("Erased. {$total} submission(s) kept, {$deleted} photograph(s) deleted.");
        $this->line('  Published figures are unchanged: the observations behind them were');
        $this->line('  already anonymous, and they still are.');

        return self::SUCCESS;
    }

    private function find(): ?Reporter
    {
        $ref = (string) $this->option('ref');
        $id = (string) $this->option('id');

        if ($ref !== '') {
            return Reporter::query()->where('external_ref', $ref)->first();
        }

        if ($id !== '') {
            return Reporter::query()->find($id);
        }

        return null;
    }
}
