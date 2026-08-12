<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The funder-facing account
|--------------------------------------------------------------------------
|
| `docs/assessment.md` is what somebody reads when deciding whether to fund or
| deploy this. Its whole value is that a reader can check it, so the parts that
| can rot silently are the ones worth holding: a link to a document that has
| moved, or an instruction naming a command that no longer exists.
|
| These cannot tell whether the prose is true. They can tell whether the things
| it points at are real, which is the failure mode a document like this actually
| has.
|
*/

use Illuminate\Contracts\Console\Kernel;

function assessment(): string
{
    return (string) file_get_contents(base_path('../docs/assessment.md'));
}

it('links only to documents that exist', function (): void {
    preg_match_all('/\]\((\.\.\/)?([A-Za-z0-9_\-\/\.]+\.md)[^)]*\)/', assessment(), $matches);

    $missing = [];

    foreach ($matches[2] as $index => $path) {
        $relative = $matches[1][$index] === '../' ? $path : 'docs/'.$path;

        if (! file_exists(base_path('../'.$relative))) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], 'Linked but not present: '.implode(', ', $missing));
});

it('names only make targets that exist', function (): void {
    $makefile = (string) file_get_contents(base_path('../Makefile'));

    preg_match_all('/^([a-z][a-z-]*):/m', $makefile, $targetMatches);
    preg_match_all('/`?make ([a-z][a-z-]*)`?/', assessment(), $usedMatches);

    $missing = array_values(array_diff(array_unique($usedMatches[1]), $targetMatches[1]));

    expect($missing)->toBe([], 'Documented but not in the Makefile: '.implode(', ', $missing));
});

it('names only artisan commands that exist', function (): void {
    $registered = array_keys(app(Kernel::class)->all());

    preg_match_all('/artisan ([a-z][a-z:_-]+)/', assessment(), $matches);

    $missing = array_values(array_diff(array_unique($matches[1]), $registered));

    expect($missing)->toBe([], 'Documented but not registered: '.implode(', ', $missing));
});

it('still says what its numbers are worth', function (): void {
    // The document previously claimed CI re-checked the figures in it. Nothing
    // does. The sentence that replaced that claim is load-bearing — without it
    // a reader is entitled to believe a stale number is a current one.
    expect(assessment())->toContain('not a live readout');
});
