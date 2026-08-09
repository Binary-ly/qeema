#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Entrypoint for the `worker` container (Laravel Horizon).
#
# The worker deliberately does NOT migrate or seed. It waits for the `app`
# container to finish bootstrapping instead. Two containers racing to migrate is
# the classic way this pattern corrupts a fresh database; the lock in
# BootstrapCommand would make it safe, but not racing at all is simpler and
# leaves one obvious owner of schema changes.

set -euo pipefail

log() { printf '[entrypoint-worker] %s\n' "$*"; }

wait_for_migrations() {
    local attempts=${MIGRATION_WAIT_ATTEMPTS:-90}
    log "waiting for the app container to finish migrating"
    for i in $(seq 1 "$attempts"); do
        if php artisan migrate:status --no-interaction >/dev/null 2>&1; then
            log "schema is present"
            return 0
        fi
        sleep 2
    done
    log "FATAL: migrations did not complete after $((attempts * 2))s"
    return 1
}

wait_for_migrations

log "caching configuration"
php artisan config:cache

log "starting horizon"
exec php artisan horizon
