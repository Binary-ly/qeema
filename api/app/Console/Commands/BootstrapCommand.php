<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

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

        if (DB::table('countries')->exists()) {
            $this->line('Reference data already present; skipping.');

            return;
        }

        $seeder = 'Database\\Seeders\\CountryConfigSeeder';

        // Tolerated rather than assumed: the container must still boot to a
        // serving state on a build where the seeder has not landed yet, so an
        // absent seeder degrades to an empty deployment instead of a crash loop.
        if (! class_exists($seeder)) {
            $this->warn('CountryConfigSeeder not available; starting with no country configured.');

            return;
        }

        $this->info('Seeding country configuration.');
        $this->call('db:seed', ['--force' => true, '--class' => $seeder]);
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

        if (DB::table('submissions')->exists()) {
            $this->line('Demo data already present; skipping.');

            return;
        }

        $seeder = 'Database\\Seeders\\DemoDataSeeder';

        if (! class_exists($seeder)) {
            $this->warn('DemoDataSeeder not available; starting with no demo history.');

            return;
        }

        $this->info('Generating synthetic demo history.');
        $this->call('db:seed', ['--force' => true, '--class' => $seeder]);
    }
}
