<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A staff account.
 *
 * This table is deliberately staff-only. Price reporters are *not* users: they
 * are anonymous device identities in `reporters`, with no login and no password,
 * because requiring a signup would suppress exactly the participation the
 * platform depends on. So the existence of a row here already means someone was
 * deliberately given back-office access.
 *
 * There is no public registration route, and the admin panel has registration
 * disabled.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Whether this account may reach a Filament panel.
     *
     * Filament denies access by default outside the `local` environment, which
     * is the safe default but would lock every real deployment out of its own
     * admin panel. Access is granted to any row in this table because, per the
     * note above, this table is staff-only by construction.
     *
     * If a deployment ever needs finer-grained back-office roles, this is the
     * single place to enforce them.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
