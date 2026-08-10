<!-- SPDX-License-Identifier: Apache-2.0 -->

# Deploying Qeema

Everything here runs on your own hardware. There is no hosted service, no
account to create, no API key to obtain, and no request leaves your network at
runtime. That is a design constraint, not a preference: the platform is meant to
keep working in places where a dependency on someone else's infrastructure is
the thing most likely to fail.

## Contents

- [Requirements](#requirements)
- [The one-command demo](#the-one-command-demo)
- [What comes up](#what-comes-up)
- [Configuration reference](#configuration-reference)
- [Adding a country](#adding-a-country)
- [Production notes](#production-notes)
- [Backup and restore](#backup-and-restore)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)

---

## Requirements

| | Minimum | Comfortable |
|---|---|---|
| Docker Engine | 24 | 26+ |
| Compose | v2 | v2 |
| RAM | 4 GB | 8 GB |
| Disk | 12 GB | 25 GB |
| CPU | 2 cores | 4 cores |

Nothing else. No PHP, no Python, no Node, no Postgres on the host.

The disk figure is dominated by the ML image: the sentence-transformer weights
are baked into it at build time (ADR D-09) so that a deployment never needs to
reach a model registry. The first build downloads them once and is slow —
expect 10–20 minutes on a normal connection. Every build after that is cached.

## The one-command demo

```bash
git clone https://github.com/<org>/qeema.git
cd qeema
make demo
```

That is the whole procedure. `make demo` builds the images, starts every
service, waits for health checks, runs migrations, seeds both shipped countries,
generates six months of synthetic history and computes the index over it.

When it finishes:

| | |
|---|---|
| Public dashboard | <http://localhost:8080> |
| API | <http://localhost:8080/api/v1> |
| API documentation | <http://localhost:8080/docs> |
| OpenAPI specification | <http://localhost:8080/api/v1/openapi.json> |
| Reporter app | <http://localhost:8080/report> |
| Admin | <http://localhost:8080/admin> |

The admin sign-in for the demo seed is `admin@qeema.local` / `qeema-demo`. It
exists so the demo is explorable and is created **only** when
`QEEMA_SEED_DEMO=true`. Change it before exposing anything.

To see the second country, use the picker in the header or append
`?country=VE`. The interface switches to Spanish and left-to-right; Libya
renders Arabic and right-to-left. Nothing in the code knows which is which —
see [Adding a country](#adding-a-country).

If you would rather not use `make`:

```bash
docker compose up -d --build
```

The application container runs migrations and seeding itself on start, under a
Postgres advisory lock so that starting several replicas at once cannot produce
two concurrent seeds.

### Other useful targets

```bash
make ps        # service status
make logs      # follow all logs
make down      # stop, keep data
make nuke      # stop and delete all volumes (destructive)
make reseed    # rebuild the schema with fresh demo data (destructive)
make verify    # everything CI runs: lint, both test suites, C3 check
make shell     # shell in the application container
make psql      # psql against the running database
```

## What comes up

| Service | Image | Purpose |
|---|---|---|
| `postgres` | `postgres:16` + pgvector, pg_trgm | System of record |
| `redis` | `redis:8-alpine` | Queues, cache, rate limiting |
| `ml` | built from `ml/` | FastAPI: matching, anomaly scoring, nowcasting |
| `app` | built from `api/` | Laravel: API, dashboard, reporter, admin |
| `worker` | same image as `app` | Horizon queue workers and the scheduler |

`redis:8-alpine` is pinned deliberately. Redis 7.4 through 7.x are RSALv2/SSPL,
which are **not** OSI-approved and would breach constraint C1. Redis 8.0
onwards is tri-licensed including AGPLv3, which is. Do not "upgrade" the pin
backwards to a 7.x tag.

The `ml` service is reached only over HTTP from `app`, never exposed publicly,
and the application degrades rather than fails when it is unavailable: a circuit
breaker opens after repeated failures, matching falls back to lexical scoring
and imputation stops, which leaves baskets visibly incomplete rather than
silently invented.

## Configuration reference

Copy `.env.example` to `.env` and edit. Everything below has a working default
for the demo; the ones you **must** change for a real deployment are marked.

### Application

| Variable | Default | Notes |
|---|---|---|
| `APP_KEY` | demo key | **Change.** `php artisan key:generate`. Must decode to exactly 32 bytes. |
| `APP_ENV` | `local` | **Change** to `production`. |
| `APP_DEBUG` | `true` | **Change** to `false`. Leaking stack traces on a public dashboard is a disclosure bug. |
| `APP_URL` | `http://localhost:8080` | **Change.** Used to build absolute URLs, including in the OpenAPI document. |
| `APP_PORT` | `8080` | Host port for the web container. |

### Database

| Variable | Default | Notes |
|---|---|---|
| `POSTGRES_DB` | `qeema` | |
| `POSTGRES_USER` | `qeema` | |
| `POSTGRES_PASSWORD` | `qeema` | **Change.** |
| `DB_TIMEZONE` | `UTC` | Leave alone. A non-UTC session timezone shifts observation timestamps and misbuckets prices recorded near midnight. |

### Redis

| Variable | Default | Notes |
|---|---|---|
| `REDIS_PASSWORD` | *(none)* | **Set** if Redis is reachable from anywhere but the compose network. |

### Countries and seeding

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_COUNTRIES_PATH` | `../countries` | Directory of `*.yaml` country configurations. |
| `QEEMA_SEED_COUNTRIES` | `*` | Comma-separated ISO codes, or `*` for every file present. |
| `QEEMA_SEED_DEMO` | `true` | **Set to `false` in production.** Controls synthetic history *and* the demo admin user. |
| `QEEMA_SEED_DEMO_MONTHS` | `6` | History depth for the demo generator. |
| `QEEMA_SEED_RANDOM_SEED` | `20260101` | Generator seed. Identical values reproduce identical data. |
| `QEEMA_ADMIN_EMAIL` | `admin@qeema.local` | Demo admin account. |
| `QEEMA_ADMIN_PASSWORD` | `qeema-demo` | **Change.** The seeder warns loudly while this is the default. |

### Machine learning

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_ML_URL` | `http://ml:8000` | |
| `QEEMA_ML_TIMEOUT` | `10` | Seconds. |
| `QEEMA_ML_CONNECT_TIMEOUT` | `2` | Seconds. |
| `QEEMA_ML_RETRIES` | `2` | |
| `QEEMA_ML_CB_FAILURES` | `5` | Consecutive failures before the circuit breaker opens. |
| `QEEMA_ML_CB_COOLDOWN` | `60` | Seconds the breaker stays open. |
| `QEEMA_ML_RETRY_DELAY_MS` | `200` | Delay between retries. |
| `QEEMA_EMBEDDING_MODEL` | `intfloat/multilingual-e5-base` | Baked into the image at build time. Changing it requires a rebuild — it is not fetched at runtime. |

### Rate limits

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_API_RATE_LIMIT` | `120` | Public API reads per minute per IP. |
| `QEEMA_SUBMISSION_RATE_LIMIT` | `60` | Price submissions per minute per IP. |
| `QEEMA_EXPORT_RATE_LIMIT` | `5` | Bulk CSV exports per minute per IP. |
| `QEEMA_API_MAX_PAGE_SIZE` | `500` | |
| `QEEMA_API_EXPORT_CHUNK` | `1000` | Rows read per chunk while streaming an export. |

## Adding a country

Add one file. Do not modify code.

1. Copy `countries/ly.yaml` to `countries/<iso2>.yaml`.
2. Edit it. Every section is documented inline. The parts that matter most:
   - `country.locales` and `country.default_locale` — text direction is derived
     from the locale, so an RTL language needs no code change.
   - `country.currency.minor_units` — the Libyan dinar has three, not two.
     Getting this wrong misprices everything by a factor of ten.
   - `basket.items` — weights **must** sum to exactly `1.000000`; the loader
     rejects the file otherwise. Weight drives coverage, quantity drives cost.
   - `canonical_items[].variants` — what reporters actually type, including
     brand names used generically and common misspellings. The matcher is only
     as good as this list.
3. Add translations at `api/lang/<locale>/dashboard.php` and
   `api/lang/<locale>/reporter.php` for any locale not already present. A test
   fails the build if a configured locale has no translation file, because the
   alternative is an English interface served under a `lang` attribute claiming
   otherwise.
4. Restart. `qeema:bootstrap` seeds countries it has not seen before and leaves
   existing ones alone.

```bash
docker compose restart app
docker compose exec app php artisan qeema:bootstrap --force
```

Then compute the index for the new country:

```bash
docker compose exec app php artisan qeema:index --country=<ISO2> \
    --from=$(date -I -d '30 days ago') --to=$(date -I)
```

The shipped pair is deliberately dissimilar — Arabic/RTL/three-decimal against
Spanish/LTR/two-decimal, opposite hemispheres, different staple foods — because
a second country resembling the first would prove very little.

## Production notes

Beyond the variables marked **Change** above:

- **Terminate TLS in front of the app container.** Nothing in this stack
  provisions certificates. Put nginx, Caddy or your existing ingress in front
  and set `APP_URL` to the public HTTPS address.
- **Do not expose `postgres`, `redis` or `ml`.** Only the `app` port needs to
  be reachable. The compose file does not publish the others; keep it that way.
- **The read API is unauthenticated by design** (constraint C6) and must stay
  that way. Rate limiting, not authentication, is what protects it.
- **Set `QEEMA_SEED_DEMO=false`** before the first production start. Otherwise
  the deployment comes up with fabricated price history and a known-password
  admin account.
- **Run the scheduler.** The `worker` service runs both Horizon and the
  scheduler; if you deploy differently, `schedule:run` must execute every
  minute or the index will never recompute.

## Backup and restore

Two things need backing up: the database, and the uploads volume. Everything
else is rebuildable from the repository.

### Backup

```bash
# Database — custom format, compressed, restorable selectively.
docker compose exec -T postgres \
    pg_dump -U qeema -Fc qeema > qeema-$(date +%F).dump

# Partner file uploads.
docker compose cp app:/var/www/html/storage/app ./storage-backup-$(date +%F)
```

The dump includes the `qeema_eval` schema, which holds the synthetic ground
truth used by the evaluation harnesses. That is intentional: without it the
published model-card figures cannot be reproduced.

Verify the dump rather than assuming it:

```bash
pg_restore --list qeema-$(date +%F).dump | head
```

### Restore

```bash
make down
docker compose up -d postgres
docker compose exec -T postgres psql -U qeema -d postgres \
    -c 'DROP DATABASE IF EXISTS qeema;' -c 'CREATE DATABASE qeema OWNER qeema;'
docker compose exec -T postgres pg_restore -U qeema -d qeema --no-owner < qeema-2026-01-01.dump
docker compose up -d
```

Then confirm the restore is coherent rather than merely complete:

```bash
docker compose exec app php artisan qeema:index   # drains anything marked stale
curl -s localhost:8080/api/v1/health | jq
```

### What is safe to lose

Redis holds queues, cache and rate-limit counters. Losing it costs you any
queued jobs — submissions already written to Postgres are unaffected, because
raw submissions are immutable and never live only in a queue.

## Upgrading

```bash
git pull
docker compose build
docker compose up -d
```

Migrations run automatically on start, under an advisory lock.

Read `PROGRESS.md` before upgrading across a release: anything that changes a
published figure is recorded there, and some fixes require a recomputation
rather than only a migration. When a change affects how the index is computed:

```bash
# Mark everything for recomputation, then drain it.
docker compose exec app php artisan qeema:index \
    --country=<ISO2> --from=<first-date> --to=<last-date>
```

**Back up before upgrading.** The project is pre-1.0 and does not yet promise a
stable schema.

### Rolling back

```bash
git checkout <previous-tag>
docker compose build
docker compose up -d
```

Down-migrations are written but not exercised in CI, so a rollback across a
migration should be done by restoring the pre-upgrade dump rather than trusting
them.

## Troubleshooting

**The first build takes forever.** It is downloading PyTorch and the embedding
weights. Roughly 10–20 minutes on a normal connection, once. `docker compose
build ml` shows progress if `make demo` is too quiet.

**`make demo` finishes but the dashboard shows no locations.** The index has not
been computed. `docker compose exec app php artisan qeema:index` and check
`docker compose logs worker`.

**Every location shows as "not comparable".** Imputation is not running, which
almost always means the `ml` service is unreachable. Check `docker compose ps
ml` and `docker compose logs ml`. This is correct behaviour rather than a bug:
without imputation, baskets with unpriced items are genuinely incomparable, and
the platform says so instead of publishing a total that omits part of the
basket.

**The admin panel 500s while the API works.** Almost always `APP_KEY`. It must
decode to exactly 32 bytes; a 31-byte key is accepted by config validation and
then rejected by AES-256.

**Timestamps look shifted by a couple of hours.** `DB_TIMEZONE` is not `UTC`.
Set it back, then recompute the affected date range.

**Port 8080 is taken.** Set `APP_PORT` in `.env`.

**Postgres will not start after a host upgrade.** A major Postgres version
change requires a dump and restore; the data volume is not upgraded in place.
Restore from a dump taken with the old version.
