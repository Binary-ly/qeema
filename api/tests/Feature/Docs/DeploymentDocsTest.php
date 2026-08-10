<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

/**
 * Keeps the deployment guide honest.
 *
 * Documentation that names a variable the code never reads is worse than no
 * documentation: an operator sets it, observes no effect, and has no way to
 * tell a typo from a bug. Every variable in the first draft of
 * `docs/deployment.md` looked plausible and nine of them did not exist.
 *
 * These tests are deliberately cheap and structural. They cannot tell whether
 * the prose is *true*, only whether the identifiers it names are real — which
 * is the failure mode that actually occurred.
 */
function deploymentDoc(): string
{
    return (string) file_get_contents(base_path('../docs/deployment.md'));
}

/**
 * Every `QEEMA_*` env var the application actually reads.
 *
 * @return list<string>
 */
function configuredEnvVars(): array
{
    $found = [];

    foreach (glob(config_path('*.php')) ?: [] as $file) {
        preg_match_all("/env\(\s*'(QEEMA_[A-Z0-9_]+)'/", (string) file_get_contents($file), $matches);
        $found = [...$found, ...$matches[1]];
    }

    // Build-time settings live in compose rather than PHP config — the
    // embedding model is baked into the ML image and never read at runtime —
    // but they are still operator-facing and belong in the guide.
    $compose = (string) file_get_contents(base_path('../docker-compose.yml'));
    preg_match_all('/\$\{(QEEMA_[A-Z0-9_]+)/', $compose, $composeMatches);

    return array_values(array_unique([...$found, ...$composeMatches[1]]));
}

it('names only environment variables the application reads', function (): void {
    preg_match_all('/`(QEEMA_[A-Z0-9_]+)`/', deploymentDoc(), $matches);

    $documented = array_unique($matches[1]);
    $real = configuredEnvVars();

    expect($documented)->not->toBeEmpty();

    $invented = array_values(array_diff($documented, $real));

    expect($invented)->toBe([], 'Documented but never read: '.implode(', ', $invented));
});

it('documents every knob the application exposes', function (): void {
    preg_match_all('/`(QEEMA_[A-Z0-9_]+)`/', deploymentDoc(), $matches);

    $documented = array_unique($matches[1]);

    // Internal, not operator-facing: the version string is stamped by the build.
    $internal = ['QEEMA_VERSION'];

    $undocumented = array_values(array_diff(configuredEnvVars(), $documented, $internal));

    expect($undocumented)->toBe([], 'Readable but undocumented: '.implode(', ', $undocumented));
});

it('quotes the real default admin password', function (): void {
    // A guide that prints the wrong demo password sends the reader hunting for
    // a broken seeder.
    expect(deploymentDoc())->toContain((string) config('qeema.admin.password'))
        ->and(deploymentDoc())->toContain((string) config('qeema.admin.email'));
});

it('references only Makefile targets that exist', function (): void {
    $makefile = (string) file_get_contents(base_path('../Makefile'));

    preg_match_all('/^([a-z][a-z-]*):/m', $makefile, $targetMatches);
    $targets = $targetMatches[1];

    preg_match_all('/`?make ([a-z][a-z-]*)`?/', deploymentDoc(), $usedMatches);
    $used = array_unique($usedMatches[1]);

    $missing = array_values(array_diff($used, $targets));

    expect($missing)->toBe([], 'Documented but not in the Makefile: '.implode(', ', $missing));
});

it('references only artisan commands that exist', function (): void {
    $registered = array_keys(app(Kernel::class)->all());

    preg_match_all('/artisan ([a-z][a-z:_-]+)/', deploymentDoc(), $matches);

    $missing = array_values(array_diff(array_unique($matches[1]), $registered));

    expect($missing)->toBe([], 'Documented but not registered: '.implode(', ', $missing));
});

it('tells the reader the export rate limit is configurable, and it is', function (): void {
    // This one was documented before it was true. The knob now exists; this
    // test is what stops it being removed while the docs still promise it.
    expect(config('qeema.api.export_rate_limit_per_minute'))->not->toBeNull()
        ->and(deploymentDoc())->toContain('QEEMA_EXPORT_RATE_LIMIT');
});
