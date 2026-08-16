<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Services\Ml\MlClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bring a fresh deployment to a working, seeded state.
 *
 * This is what makes `docker compose up` satisfy constraint C2 with no manual
 * steps. It is safe to run repeatedly and safe to run concurrently:
 *
 *  - a distributed lock keeps two containers from migrating at once;
 *  - migrations are tracked, so a second run is a no-op;
 *  - seeding checks whether it already happened rather than re-inserting.
 */
final class BootstrapCommand extends Command
{
    /** How far back to publish on a first boot. */
    private const DEMO_INDEX_DAYS = 30;

    protected $signature = 'qeema:bootstrap
                            {--force : Run without interactive confirmation}
                            {--fresh : Drop and rebuild the schema first (destructive)}
                            {--skip-demo : Migrate and seed reference data but no demo data}';

    protected $description = 'Migrate, seed and make a deployment ready to serve';

    /**
     * How long to wait for another container to finish bootstrapping before
     * giving up. Generous, because a first-run migration on a cold database is
     * legitimately slow and a premature failure would crash-loop the container.
     */
    private const LOCK_SECONDS = 600;

    private const LOCK_WAIT_SECONDS = 300;

    public function handle(): int
    {
        $lock = Cache::lock('qeema:bootstrap', self::LOCK_SECONDS);

        try {
            // block() waits for whoever holds the lock rather than failing, so a
            // worker that boots a moment before the app simply waits its turn.
            return (int) $lock->block(self::LOCK_WAIT_SECONDS, fn (): int => $this->bootstrap());
        } catch (LockTimeoutException) {
            $this->error('Timed out waiting for another container to finish bootstrapping.');

            return self::FAILURE;
        }
    }

    private function bootstrap(): int
    {
        if (! $this->ensureExtensions()) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Dropping all tables (--fresh).');
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->call('migrate', ['--force' => true]);
        }

        $this->seedAdminUser();
        $this->seedReferenceData();

        if (! $this->option('skip-demo') && config('qeema.seed.demo')) {
            $this->seedDemoData();
        }

        // Seeding prices is not the same as publishing an index. Without this,
        // `make demo` came up with two fully-seeded countries and an empty
        // dashboard — every endpoint returning 200 with nothing in it, which is
        // the most misleading way for a demo to fail. Constraint C2 asks for a
        // system that works after one command, not one that is merely running.
        $this->computeIndex();

        $this->info('Bootstrap complete.');

        return self::SUCCESS;
    }

    /**
     * Fail fast and loudly if the database lacks pgvector or pg_trgm.
     *
     * Without this the stack boots happily and then fails on the first match
     * query, which is a far more confusing failure to debug than a refusal at
     * startup naming the missing extension.
     */
    private function ensureExtensions(): bool
    {
        try {
            $installed = DB::table('pg_extension')->pluck('extname')->all();
        } catch (Throwable $e) {
            $this->error('Cannot reach the database: '.$e->getMessage());

            return false;
        }

        $missing = array_diff(['vector', 'pg_trgm'], $installed);

        if ($missing !== []) {
            $this->error(sprintf(
                'Missing required PostgreSQL extension(s): %s. '
                .'Use the pgvector/pgvector:pg16 image or run infra/postgres/001-extensions.sql.',
                implode(', ', $missing)
            ));

            return false;
        }

        return true;
    }

    /**
     * Create the initial admin account, so the panel is reachable on first boot
     * without exec-ing into a container.
     */
    private function seedAdminUser(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $seeder = 'Database\\Seeders\\AdminUserSeeder';

        if (! class_exists($seeder)) {
            return;
        }

        $this->call('db:seed', ['--force' => true, '--class' => $seeder]);
    }

    /**
     * Seed country configuration — currencies, locations, units, canonical
     * items and baskets — from countries/*.yaml.
     */
    private function seedReferenceData(): void
    {
        if (! Schema::hasTable('countries')) {
            $this->warn('Reference tables are not present yet; skipping reference seed.');

            return;
        }

        // Per-country, not "any country at all". The coarse check meant that
        // dropping a new countries/*.yaml into a running deployment did nothing
        // and said nothing — while the config files themselves promise that
        // adding a country is exactly that and no code change. An operator
        // would have had no way to tell the difference between "ignored" and
        // "broken".
        //
        // The importer is idempotent throughout (updateOrCreate), so re-running
        // for a country already present is safe; the guard exists only to keep
        // routine container restarts cheap.
        $missing = $this->unseededCountryCodes();

        if ($missing === []) {
            $this->line('All configured countries are already seeded; skipping.');

            return;
        }

        $this->info('Seeding country configuration: '.implode(', ', $missing).'.');

        $seeder = 'Database\\Seeders\\CountryConfigSeeder';

        // Tolerated rather than assumed: the container must still boot to a
        // serving state on a build where the seeder has not landed yet, so an
        // absent seeder degrades to an empty deployment instead of a crash loop.
        if (! class_exists($seeder)) {
            $this->warn('CountryConfigSeeder not available; starting with no country configured.');

            return;
        }

        $this->call('db:seed', ['--force' => true, '--class' => $seeder]);
    }

    /**
     * Publish the index over whatever history now exists.
     *
     * Bounded to the demo window rather than all of time: recomputing six
     * months for every country on every container start would dominate boot,
     * and older snapshots do not change unless their observations do — the
     * staleness observer marks those individually.
     */
    private function computeIndex(): void
    {
        if (! Schema::hasTable('index_snapshots')) {
            return;
        }

        if (! DB::table('price_observations')->exists()) {
            $this->line('No observations to index yet.');

            return;
        }

        $countries = DB::table('countries')->pluck('code');

        foreach ($countries as $code) {
            $published = DB::table('index_snapshots')
                ->join('countries', 'countries.id', '=', 'index_snapshots.country_id')
                ->where('countries.code', $code)
                ->exists();

            if ($published) {
                continue;
            }

            // Anchors first: the calculator reads them to produce a level, so a
            // snapshot computed before its basket is anchored publishes a null
            // level and would need recomputing.
            $this->info("Anchoring baskets for {$code}...");
            $this->call('qeema:index:link', ['--country' => $code]);

            $this->info("Computing the index for {$code}...");

            $this->call('qeema:index', [
                '--country' => $code,
                '--from' => CarbonImmutable::now()->subDays(self::DEMO_INDEX_DAYS)->toDateString(),
                '--to' => CarbonImmutable::now()->toDateString(),
            ]);
        }

        $this->warmMatcher();
    }

    /**
     * Build each country's catalogue index before the first submission needs it.
     *
     * The matching service embeds a catalogue on first sight, which for a few
     * hundred variants takes tens of seconds — far longer than the ten-second
     * request timeout. Without this the first price submitted after a fresh
     * boot times out, the circuit opens, and a price that should have reached
     * the index goes to a human instead. That is exactly what happened when one
     * country's catalogue grew from 133 variants to 675: the end-to-end test
     * failed on `cURL error 28` at the very first match.
     *
     * Failure here is not fatal. A deployment with no matching service is a
     * supported state — the platform routes to human review — so this reports
     * and continues rather than refusing to finish bootstrapping.
     */
    private function warmMatcher(): void
    {
        $client = app(MlClient::class);

        if (! $client->isAvailable()) {
            $this->warn('Matching service unavailable; skipping warm-up.');

            return;
        }

        foreach (Country::query()->where('is_active', true)->get() as $country) {
            $this->info("Warming the matcher for {$country->code}...");

            if (! $client->warm($country)) {
                $this->warn("  Could not warm {$country->code}; the first match will be slow.");
            }
        }
    }

    /**
     * Country codes present in countries/*.yaml but not yet in the database.
     *
     * @return list<string>
     */
    private function unseededCountryCodes(): array
    {
        $directory = (string) config('qeema.countries_path');

        if (! is_dir($directory)) {
            return [];
        }

        $configured = [];

        foreach (glob($directory.'/*.yaml') ?: [] as $path) {
            // The filename is the ISO code by convention, which is enough to
            // decide whether to run the seeder. The loader does the real
            // parsing and validation immediately afterwards.
            $configured[] = strtoupper(pathinfo($path, PATHINFO_FILENAME));
        }

        if ($configured === []) {
            return [];
        }

        $seeded = DB::table('countries')->pluck('code')->map(strtoupper(...))->all();

        return array_values(array_diff($configured, $seeded));
    }

    /**
     * Seed the synthetic demo history so a fresh deployment is demonstrable
     * before a single real reporter exists.
     */
    private function seedDemoData(): void
    {
        if (! Schema::hasTable('submissions')) {
            $this->warn('Submission tables are not present yet; skipping demo seed.');

            return;
        }

        // Also per-country: a second country added later needs its own demo
        // history, and the seeder skips countries that already have one.
        $withoutDemo = DB::table('countries')
            ->whereNotIn('id', fn ($q) => $q->select('country_id')->distinct()->from('submissions'))
            ->pluck('code')
            ->all();

        if ($withoutDemo === []) {
            $this->line('Demo data already present for every country; skipping.');

            return;
        }

        $this->info('Seeding demo history for: '.implode(', ', $withoutDemo).'.');

        $seeder = 'Database\\Seeders\\DemoDataSeeder';

        if (! class_exists($seeder)) {
            $this->warn('DemoDataSeeder not available; starting with no demo history.');

            return;
        }

        $this->info('Generating synthetic demo history.');
        $this->call('db:seed', ['--force' => true, '--class' => $seeder]);
    }
}
