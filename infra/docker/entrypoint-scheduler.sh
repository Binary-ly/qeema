#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Entrypoint for the `scheduler` container.
#
# A separate service rather than another process inside the worker, on purpose.
# If the scheduler dies inside the worker container, `docker compose ps` still
# reports the worker healthy — Horizon is fine — while the index quietly stops
# updating. That is precisely the invisible failure the scheduler exists to
# prevent, so it gets its own container, its own restart policy and its own
# healthcheck.
#
# Like the worker, it never migrates or seeds: the app container owns the schema.

set -euo pipefail

log() { printf '[entrypoint-scheduler] %s\n' "$*"; }

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

# Record one heartbeat immediately so the healthcheck has something to read
# before the first scheduled minute elapses.
php artisan qeema:scheduler:heartbeat || true

log "starting the scheduler"
exec php artisan schedule:work
