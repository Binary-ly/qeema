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
- [Reviewing what the pipeline could not decide](#reviewing-what-the-pipeline-could-not-decide)
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
git clone https://github.com/Binary-ly/qeema.git
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
| `worker` | same image as `app` | Horizon queue workers: resolution and anomaly screening |
| `scheduler` | same image as `app` | The clock: reconciliation, index drain, roll-forward |

**The `scheduler` service is load-bearing.** It runs the tasks that turn stored
data into published data: reconciling anything the fast path missed, draining
stale snapshots so corrections reach published figures, and rolling the index
forward so new calendar days appear at all. If it stops, the API keeps
answering, the dashboard keeps rendering, every other container stays healthy,
and the index quietly stops moving. That is why it is a separate service with
its own healthcheck rather than a second process inside `worker`: its own
heartbeat is read back every minute, so a stopped clock shows up in
`docker compose ps` instead of going unnoticed.

To check it by hand:

```bash
docker compose exec scheduler php artisan qeema:scheduler:heartbeat --check
docker compose exec app php artisan schedule:list
```

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
| `QEEMA_ML_WARM_TIMEOUT` | `300` | Seconds, for building a catalogue index at boot only. The matcher embeds a catalogue on first sight — tens of seconds for a few hundred variants — so `qeema:bootstrap` warms it with this budget. Without that the first submission after a deployment times out against `QEEMA_ML_TIMEOUT` and goes to review. |
| `QEEMA_ML_CONNECT_TIMEOUT` | `2` | Seconds. |
| `QEEMA_ML_RETRIES` | `2` | |
| `QEEMA_ML_CB_FAILURES` | `5` | Consecutive failures before the circuit breaker opens. |
| `QEEMA_ML_CB_COOLDOWN` | `60` | Seconds the breaker stays open. |
| `QEEMA_ML_RETRY_DELAY_MS` | `200` | Delay between retries. |
| `QEEMA_EMBEDDING_MODEL` | `intfloat/multilingual-e5-base` | Baked into the image at build time. Changing it requires a rebuild — it is not fetched at runtime. |
| `QEEMA_ML_NOWCAST_MODEL_DIR` | `/models` | Where fitted nowcast models are kept between restarts, backed by the `ml-models` volume. Without it the models live only in memory and every restart drops each country to a fallback heuristic until the next training run. |

### Rate limits

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_API_RATE_LIMIT` | `120` | Public API reads per minute per IP. |
| `QEEMA_SUBMISSION_RATE_LIMIT` | `60` | Price submissions per minute per IP. |
| `QEEMA_EXPORT_RATE_LIMIT` | `5` | Bulk CSV exports per minute per IP. |
| `QEEMA_API_MAX_PAGE_SIZE` | `500` | |
| `QEEMA_API_EXPORT_CHUNK` | `1000` | Rows read per chunk while streaming an export. |

### The ingestion pipeline

These control how an inbound submission becomes a published figure. The
defaults suit a pilot; the two worth understanding before changing are
`QEEMA_PIPELINE_MAX_ATTEMPTS` and `QEEMA_INDEX_PUBLISH_GRACE`.

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_PIPELINE_QUEUE_LIVE` | `pipeline-live` | Queue for submissions arriving through the API. Kept separate so a bulk import cannot decide how long a reporter waits. Renaming it means renaming the matching Horizon supervisor in `api/config/horizon.php`. |
| `QEEMA_PIPELINE_QUEUE_BULK` | `pipeline-bulk` | Queue for partner imports and sweeper catch-up. |
| `QEEMA_PIPELINE_MAX_ATTEMPTS` | `5` | Attempts before a submission is handed to a human with the error attached. Counted on the submission row, so it survives re-dispatch: five means five, however many times the work was queued. |
| `QEEMA_PIPELINE_SWEEP_AGE` | `120` | Seconds a submission must have been pending before the reconciler adopts it. Stops the sweeper racing the dispatch that already happened. |
| `QEEMA_PIPELINE_SWEEP_LIMIT` | `500` | Dispatches of each kind per sweep. A backlog is worked through over several ticks rather than dumped on the queue at once. |
| `QEEMA_PIPELINE_SCORE_WINDOW_HOURS` | `24` | How far back the sweeper looks for observations nobody screened. Bounded on purpose: a seeded deployment holds tens of thousands of observations written wholesale rather than through the pipeline, and an unbounded sweep would re-dispatch them every minute forever. |
| `QEEMA_PIPELINE_ALERT_MINUTES` | `15` | How late the pipeline may be before health reports it degraded. Generous relative to the one-minute cadence of the sweeper and the drain: an alert that fires on ordinary conditions is one people learn to close. |
| `QEEMA_REVIEW_ALERT_DAYS` | `7` | How long a submission may wait for a human before the review queue is reported as unworked. Age is the signal, not size — a large queue being drained is healthy. |

### Index publication

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_INDEX_DRAIN_LIMIT` | `500` | Stale snapshots recomputed per minute. |
| `QEEMA_INDEX_PUBLISH_GRACE` | `60` | Seconds a snapshot must have been stale before it is recomputed. An observation marks its snapshots stale the instant it is written, but anomaly screening lands a moment later; without this window a figure containing an unscreened price is briefly published and then corrected. Lower it for a livelier index, at the cost of that window. |
| `QEEMA_INDEX_BACKFILL_DAYS` | `3` | How many days back the hourly roll-forward looks for dates that never got a snapshot. |

### Exchange rates

| Variable | Default | Notes |
|---|---|---|
| `QEEMA_FX_FETCH_ENABLED` | `true` | Master switch for automatic fetching. A no-op for any country on manual entry, which is every country by default. |
| `QEEMA_FX_HTTP_TIMEOUT` | `10` | Seconds to wait on a configured rate source. |

**The platform ships with no rate source for any currency, and that is
deliberate.** Constraint C1 is that no proprietary or paid third-party API sits
in the runtime path, and the parallel-market feeds that exist for these
currencies are behind an API key. Shipping an integration with one would give
every deployment an account to create and a secret to keep.

So the default everywhere is `manual`: an operator enters rates under **Admin →
FX rates**, and the health check warns before the last one goes stale enough for
`cost_usd` to be withheld. **A rate you typed is never overwritten by a fetched
one** — both are stored, and the resolver prefers the human.

If you have a source, describe it in your country file. The application knows
how to read *a* JSON endpoint and nothing about which one:

```yaml
fx:
  provider: generic_http
  rate_type: parallel
  max_staleness_days: 7
  config:
    url: https://example.org/api/rates
    parallel_path: data.parallel.sell   # dot path into the response
    official_path: data.official.sell
    date_path: data.updated_at          # optional; defaults to today
    auth_header: Authorization          # optional
    auth_token_env: FX_API_TOKEN      # the token is read from this
                                        # environment variable, so it never
                                        # goes into the country file
```

Then set `FX_API_TOKEN` in your environment and check it with:

```bash
docker compose exec app php artisan qeema:fx:fetch --country=<ISO2>
```

#### A worked example

Finding a parallel-rate source is the hardest part of deploying this, so here is
one written out in full. It is an example of the shape, not a recommendation and
not a dependency.

[Fulus](https://fulus.ly) publishes Libyan dinar parallel-market rates. It is a
**commercial third-party service**: it requires an account, an API token and an
active subscription, and its free tier covers USD only. It is operated by Binary
Tech Ltd, who also wrote this platform — disclosed here so the relationship is
visible rather than discovered.

Nothing in Qeema ships configured against it, nothing depends on it, and it is
named here only because a worked example of a real endpoint is more useful than
`example.org`. Any other JSON source, or manual entry, works identically.

```yaml
fx:
  provider: generic_http
  base_currency: USD
  rate_type: parallel
  max_staleness_days: 7
  config:
    url: https://fulus.ly/api/v1/rates/current?currency=USD
    parallel_path: data.rate            # {"data":{"currency":"USD","rate":6.85,…}}
    date_path: data.timestamp
    auth_header: Authorization
    auth_token_env: QEEMA_FX_TOKEN
```

The token is used verbatim as the header value, so for a bearer scheme the
environment variable includes the word:

```bash
QEEMA_FX_TOKEN="Bearer your-token-here"
```

Using a keyed commercial source is an operator's decision about their own
deployment and does not change what the platform depends on. Qeema itself must
keep working with no source at all, which is why manual entry stays the default
and why the health check warns before a stale rate withdraws dollar figures.

The fetch runs hourly. It refuses any URL that is not http/https, that carries
credentials, or that resolves to a private, loopback or link-local address —
which is what stops a configuration file being turned into a way to read the
host's cloud metadata service.

Two queue settings are not Qeema's own but matter here:
`REDIS_QUEUE_RETRY_AFTER` (default `300`) must exceed every job timeout and
every Horizon supervisor timeout, or a slow job is handed to a second worker
while the first is still running.

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

## Reviewing what the pipeline could not decide

**Admin → Ingestion → Review queue**, at `/admin/review-queue`. The navigation
item carries the size of the backlog.

The pipeline refuses to guess. When the matcher is unsure which item a phrase
means, when the matching service was unreachable, or when screening distrusts a
price, the submission goes here instead of into the published index. Somebody
has to work this queue, and a deployment where nobody does is a deployment that
publishes only what a machine was confident about.

Each row carries what a decision needs: the text exactly as it was typed, the
matcher's suggestion and how strongly it holds it, the screening verdict and
its reasons, the reporter's history, and the basket weight of the item in
question — sort by that last column when you have an hour rather than a day,
because it is what decides how much of the published figure your hour actually
corrects.

Two things are worth knowing before you start:

- **Approving teaches the matcher.** Every confirmed decision becomes a known
  variant, so the phrase that defeated it today resolves automatically
  tomorrow. This is the mechanism by which the queue shrinks rather than
  refilling; a reviewer who rejects everything ambiguous instead of confirming
  it will be doing the same work next week.
- **Use the bulk action for the common case.** Most of the queue is the matcher
  having been right and merely unsure. Select those rows and approve the
  suggested match in one go; anything without a suggestion is left alone and
  the count is reported back to you.

Approved prices are not published instantly. The observation marks the affected
snapshots stale and the scheduled drain republishes them within a couple of
minutes — the same path an automatic resolution takes.

For what to check when something is not working, see
[operations.md](operations.md) — the runbook for a platform whose every failure
mode looks like silence.

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
