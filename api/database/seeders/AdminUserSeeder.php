<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the initial admin account so a fresh deployment has a way in.
 *
 * Without this, `docker compose up` would produce a running admin panel that
 * nobody can log into, and the reviewer would have to exec into a container to
 * create a user — exactly the manual step constraint C2 forbids.
 *
 * The default password is weak and public. That is acceptable only because it
 * is created solely when demo seeding is enabled, and the deployment guide and
 * SECURITY.md both say to change it. An operator running for real sets
 * QEEMA_ADMIN_PASSWORD, and the credentials are printed at boot so they cannot
 * be missed.
 */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // config(), not env(): the container entrypoint caches configuration
        // before seeding runs, and env() returns null once that has happened.
        $email = (string) config('qeema.admin.email');
        $password = (string) config('qeema.admin.password');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->line("Admin user {$email} already exists; leaving it alone.");

            return;
        }

        User::query()->create([
            'name' => 'Qeema Admin',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Created admin user {$email}");

        if ($password === 'qeema-demo') {
            $this->command?->warn(
                'This deployment uses the default demo admin password. '
                .'Set QEEMA_ADMIN_PASSWORD before exposing it to a network.'
            );
        }
    }
}
