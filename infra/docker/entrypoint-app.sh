#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Entrypoint for the `app` container.
#
# Constraint C2 says a clean machine runs one command and gets a seeded, working
# system with no manual steps. That means migrating and seeding happen here, and
# they must be safe when `app` and `worker` boot at the same moment. The
# bootstrap command takes a distributed lock to make that safe; see
# App\Console\Commands\BootstrapCommand.

set -euo pipefail

log() { printf '[entrypoint-app] %s\n' "$*"; }

wait_for_postgres() {
    local attempts=${DB_WAIT_ATTEMPTS:-60}
    log "waiting for postgres at ${DB_HOST}:${DB_PORT}"
    for i in $(seq 1 "$attempts"); do
        if pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -q; then
            log "postgres is accepting connections"
            return 0
        fi
        sleep 2
    done
    log "FATAL: postgres did not become ready after $((attempts * 2))s"
    return 1
}

wait_for_postgres

log "caching configuration"
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Migrate and seed under a distributed lock. Idempotent: a restart re-runs this
# and does nothing, because migrations are tracked and seeding checks a marker.
log "running platform bootstrap (migrate + seed)"
php artisan qeema:bootstrap --force

log "linking public storage"
php artisan storage:link --force || true

log "starting nginx and php-fpm"
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
