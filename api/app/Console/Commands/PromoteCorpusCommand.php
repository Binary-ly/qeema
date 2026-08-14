<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Synthetic\ReporterCorpus;
use App\Support\Text\TextNormalizer;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Promote reviewed corpus wordings into a country's catalogue as variants.
 *
 * **Why this exists.** The matcher resolves text against catalogue variants.
 * Libya shipped with 133 of them while its corpus held 689 wordings that real
 * people type, and the matcher had never been given one — so every matching
 * figure measured against it was measuring the platform on vocabulary it did
 * not have. Handing the vocabulary over was worth **+19.1 points of top-1 on a
 * held-out half of the corpus**: 67.5% to 86.6% on wordings never seen.
 *
 * That was done once, by hand, with a throwaway script. This is the same thing
 * as something an operator can run, for any country, and run again safely.
 *
 * **What it will not promote.** Four exclusions, each protecting against a way
 * a corpus can quietly poison a catalogue:
 *
 * - wordings of an item the corpus lists under `hold` — not trusted yet
 * - wordings already present, so re-running changes nothing
 * - a wording filed under two different items, which cannot be a variant of
 *   either without teaching the matcher to conflate them
 * - a wording that is also a distractor, which is a labelling contradiction:
 *   the corpus asserts both that it means this item and that it means nothing
 *
 * **Where it writes.** Into `countries/<code>.yaml`, not the database. The
 * catalogue is country configuration, it belongs in version control where it
 * can be diffed and argued with, and it must survive a clean `docker compose
 * up` without anyone remembering to run a command (C2). Run
 * `qeema:config:import` afterwards to apply it.
 *
 * **The cost of promotion**, which the corpus records and this prints: a
 * promoted wording is no longer a test. It is catalogue vocabulary, and
 * measuring the matcher against it afterwards measures memorisation. Keep some
 * held back, or measure against real market data.
 */
final class PromoteCorpusCommand extends Command
{
    protected $signature = 'qeema:corpus:promote
                            {--country= : ISO code}
                            {--dry-run : Report what would be promoted and write nothing}';

    protected $description = 'Add reviewed corpus wordings to a country catalogue as matcher variants';

    public function handle(TextNormalizer $normalizer): int
    {
        $code = strtoupper((string) $this->option('country'));

        if ($code === '') {
            $this->error('--country is required.');

            return self::FAILURE;
        }

        $directory = (string) config('qeema.countries_path');
        $path = $directory.'/'.strtolower($code).'.yaml';

        if (! is_file($path)) {
            $this->error("No country configuration at {$path}.");

            return self::FAILURE;
        }

        $corpus = ReporterCorpus::forCountry($code);

        if ($corpus->isEmpty()) {
            $this->error('No corpus at '.$directory.'/corpus/'.strtolower($code).'.json — nothing to promote.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $config */
        $config = Yaml::parseFile($path);
        /** @var list<array<string, mixed>> $items */
        $items = $config['canonical_items'] ?? [];

        if ($items === []) {
            $this->error("Country {$code} has no canonical_items to promote into.");

            return self::FAILURE;
        }

        $plan = $this->plan($corpus, $items, $normalizer);

        $this->report($plan, $code);

        if ($plan['promote'] === []) {
            $this->info('Nothing to promote; the catalogue already has everything this corpus offers.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        file_put_contents($path, $this->rewrite((string) file_get_contents($path), $plan['promote']));

        $this->newLine();
        $this->info("Wrote {$path}.");
        $this->line("  Apply it with: php artisan qeema:config:import --country={$code}");
        $this->warn('  Promoted wordings are catalogue vocabulary now, not a test set — a');
        $this->warn('  matching score measured against them measures memorisation.');

        return self::SUCCESS;
    }

    /**
     * Decide what may be promoted, and why the rest may not.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{promote: array<string, list<string>>, skipped: array<string, int>}
     */
    private function plan(ReporterCorpus $corpus, array $items, TextNormalizer $normalizer): array
    {
        $known = [];

        foreach ($items as $item) {
            $code = (string) $item['code'];
            $texts = array_merge(
                [(string) $item['name_en']],
                isset($item['name_local']) ? [(string) $item['name_local']] : [],
                array_map(strval(...), $item['variants'] ?? []),
            );

            foreach ($texts as $text) {
                $known[$code][$normalizer->normalize($text)] = true;
            }
        }

        // A wording claimed by more than one item is evidence the corpus is
        // ambiguous there, not a variant of whichever item is read first.
        $owners = [];

        foreach ($corpus->phrasings() as $code => $wordings) {
            foreach ($wordings as $wording) {
                $owners[$normalizer->normalize($wording)][$code] = true;
            }
        }

        $distractors = [];

        foreach ($corpus->distractors() as $distractor) {
            $distractors[$normalizer->normalize($distractor)] = true;
        }

        $hold = array_flip($corpus->hold());
        $promote = [];
        $skipped = [];

        foreach ($corpus->phrasings() as $code => $wordings) {
            if (! isset($known[$code])) {
                $skipped['no such item in the catalogue'] = ($skipped['no such item in the catalogue'] ?? 0) + count($wordings);

                continue;
            }

            if (isset($hold[$code])) {
                $skipped['held back by the corpus'] = ($skipped['held back by the corpus'] ?? 0) + count($wordings);

                continue;
            }

            foreach ($wordings as $wording) {
                $normalised = $normalizer->normalize($wording);

                $reason = match (true) {
                    $normalised === '' => 'empty after normalisation',
                    isset($known[$code][$normalised]) => 'already a variant',
                    count($owners[$normalised]) > 1 => 'ambiguous across items',
                    isset($distractors[$normalised]) => 'also listed as a distractor',
                    default => null,
                };

                if ($reason !== null) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                    continue;
                }

                $promote[$code][] = $wording;
                $known[$code][$normalised] = true;
            }
        }

        return ['promote' => $promote, 'skipped' => $skipped];
    }

    /**
     * @param  array{promote: array<string, list<string>>, skipped: array<string, int>}  $plan
     */
    private function report(array $plan, string $code): void
    {
        $total = array_sum(array_map('count', $plan['promote']));

        $this->info(sprintf('%s: %d wording(s) to promote across %d item(s).', $code, $total, count($plan['promote'])));

        foreach ($plan['promote'] as $item => $wordings) {
            $this->line(sprintf('  %-32s +%d', $item, count($wordings)));
        }

        if ($plan['skipped'] !== []) {
            $this->newLine();
            $this->line('Not promoted:');

            arsort($plan['skipped']);

            foreach ($plan['skipped'] as $reason => $count) {
                $this->line(sprintf('  %5d  %s', $count, $reason));
            }
        }
    }

    /**
     * Insert variants into the YAML by editing the lines that declare them.
     *
     * Deliberately textual rather than parse-and-dump. Re-emitting the file
     * would strip every comment in it and reorder keys, turning a reviewable
     * diff into an unreadable one — and this file is meant to be read by whoever
     * maintains the country.
     *
     * @param  array<string, list<string>>  $promote
     */
    private function rewrite(string $yaml, array $promote): string
    {
        $out = [];
        $item = null;

        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^\s*-\s+code:\s*(\S+)/', $line, $match) === 1) {
                $item = $match[1];
            }

            if (
                $item !== null
                && isset($promote[$item])
                && preg_match('/^(\s*)variants:\s*\[(.*)\]\s*$/', $line, $match) === 1
            ) {
                $indent = $match[1];
                $bullet = $indent.'  - ';

                $out[] = $indent.'variants:';

                foreach (array_filter(array_map('trim', explode(',', $match[2]))) as $existing) {
                    $out[] = $bullet.$existing;
                }

                $out[] = $indent.'  # Promoted from the reporter corpus by qeema:corpus:promote.';

                foreach ($promote[$item] as $wording) {
                    $out[] = $bullet.$wording;
                }

                unset($promote[$item]);

                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
