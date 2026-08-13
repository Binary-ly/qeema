<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every command a runbook tells somebody to type
|--------------------------------------------------------------------------
|
| Written after documenting `qeema:config:import` in the operations runbook
| before it existed. The mistake was caught by chance; an operator following the
| revision procedure during an incident would have caught it instead.
|
| Documentation that names a command which does not exist is worse than no
| documentation, because it is followed confidently.
|
*/

use Illuminate\Contracts\Console\Kernel;

it('names only artisan commands that exist', function (): void {
    $registered = array_keys(app(Kernel::class)->all());
    $missing = [];

    foreach (glob(base_path('../docs/*.md')) ?: [] as $path) {
        preg_match_all('/artisan\s+([a-z][a-z0-9:_-]*)/', (string) file_get_contents($path), $matches);

        foreach (array_unique($matches[1]) as $command) {
            if (! in_array($command, $registered, true)) {
                $missing[] = basename($path).': '.$command;
            }
        }
    }

    expect($missing)->toBe([], 'Documented but not registered — '.implode(', ', $missing));
});

it('documents every scheduled task in the operations runbook', function (): void {
    // The runbook's table is what an operator reads to know what should be
    // running. A task added to the schedule and left out of that table is
    // invisible until it stops and nobody knows it existed.
    $runbook = (string) file_get_contents(base_path('../docs/operations.md'));
    $schedule = (string) file_get_contents(base_path('routes/console.php'));

    preg_match_all("/Schedule::command\(\s*'([a-z][a-z0-9:_ =-]*)'/", $schedule, $literal);
    preg_match_all('/Schedule::command\(\s*([A-Za-z]+)::class/', $schedule, $classes);

    $undocumented = [];

    foreach ($literal[1] as $command) {
        // `queue:prune-failed --hours=168` is scheduled with its arguments.
        $name = (string) (preg_split('/\s+/', trim($command))[0] ?? '');

        if (! str_contains($runbook, $name)) {
            $undocumented[] = $name;
        }
    }

    foreach ($classes[1] as $class) {
        $fqcn = 'App\\Console\\Commands\\'.$class;

        if (! class_exists($fqcn)) {
            continue;
        }

        $signature = (new ReflectionClass($fqcn))->getDefaultProperties()['signature'] ?? '';

        // Signatures are multi-line heredocs in this codebase, so the name ends
        // at the first whitespace of any kind rather than at a space.
        $name = (string) (preg_split('/\s+/', trim((string) $signature))[0] ?? '');

        if ($name !== '' && ! str_contains($runbook, $name)) {
            $undocumented[] = $name;
        }
    }

    expect($undocumented)->toBe([], 'Scheduled but absent from the runbook: '.implode(', ', $undocumented));
});
