# Qeema — Technical Plan

**Qeema** (قيمة — "value") publishes a live, child-weighted affordability index for crisis
economies. It ingests price observations from three sources, resolves them to canonical basket
items, costs a child-weighted basket per location against a live parallel-market exchange rate,
and publishes the result through a public API and dashboard.

This document is the working technical plan. It is updated as decisions change; the decision log
in §2 is append-only.

---

## 1. Non-negotiable constraints

These come from the UNICEF Venture Fund requirements. Every design choice below is traceable to
them, and §13 is a compliance matrix.

| # | Constraint | Enforcement |
|---|---|---|
| C1 | No proprietary or paid third-party API anywhere in the runtime path. All models local, all deps OSI-licensed. | `make licenses` generates `docs/LICENSES.md` from both lockfiles; CI fails on a non-OSI SPDX id. Model weights MIT. |
| C2 | `docker compose up` brings the whole system up, seeded and working, on a clean Docker-only machine. No manual steps. | Entrypoint runs migrate+seed idempotently under an advisory lock. CI job boots the stack from a clean checkout and asserts the dashboard and API respond. |
| C3 | Country-agnostic. Country, currency, locations, basket, language, FX source are all configuration. | Zero Libya literals outside `countries/*.yaml`. CI greps the source tree for banned literals (`LYD`, `Libya`, `Tripoli`, `fulus`) outside the allowed paths. |
| C4 | Apache-2.0, open source end to end. | `LICENSE`, `NOTICE`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` present; SPDX headers on source files. |
| C5 | ≥80% unit test coverage, enforced in CI, from the first phase. | Pest coverage gate `--min=80`; pytest `fail_under = 80`. Gate is live from Phase 0. |
| C6 | The public data is the product: public, unauthenticated read, OpenAPI 3, real-time. | No auth middleware on `/api/v1/*` read routes. Spec validated in CI; contract tests assert responses match the spec. |

**Escalation rule.** If a constraint turns out to be impossible or self-contradictory, stop and
report it rather than silently working around it. One such tension has already been found and
resolved — see D-07.

---

## 2. Decision log

Append-only. Each entry records what was decided, why, and what was rejected.

### D-01 — Latest stable framework majors, not the majors named in the brief
**Decided:** Laravel 13.24, Filament 5.7.6, Livewire 4.3.5, Pest 5.0.4 / PHPUnit 13.2.6.
**Why:** The brief named Laravel 12 / Filament v4 / Livewire 3 but granted "or latest stable" for
Laravel and Filament. Filament 5 transitively pins Livewire 4, so those two majors cannot be
mixed. Shipping a funding application on a stack already one major behind is the worse outcome.
Confirmed with the user before proceeding.
**Rejected:** Laravel 12 + Filament 4 + Livewire 3 (resolves cleanly, but already superseded).

### D-02 — `zircote/swagger-php` for OpenAPI, not `dedoc/scramble`
**Decided:** Generate the OpenAPI 3 document from PHP attributes with swagger-php (Apache-2.0).
**Why:** Scramble 0.12 does not support Laravel 13 — Composer refuses to resolve it. swagger-php
is framework-agnostic, so it cannot be blocked by a Laravel-version lag again.
**Rejected:** Scramble (incompatible); hand-written YAML (drifts from the code).
**Mitigation for drift:** `league/openapi-psr7-validator` asserts every API test response against
the generated spec, so code and spec cannot diverge silently.

### D-03 — Repository layout
```
qeema/
  api/          Laravel 13 — public API, admin, dashboard, reporter PWA
  ml/           FastAPI — matching, anomaly scoring, nowcasting
  countries/    Country configuration (the only place country facts live)
  contracts/    JSON Schemas shared by PHP and Python contract tests
  e2e/          Playwright
  infra/        Dockerfiles, entrypoints, compose overrides
  docs/         Architecture, ADRs, model cards, licenses
  docker-compose.yml, Makefile, LICENSE, NOTICE, PLAN.md, PROGRESS.md
```
**Why:** `api/` and `ml/` are independently deployable and independently testable, which is what
the HTTP boundary between Laravel and the ML service is for.

### D-04 — Variants get a table, not a `text[]` column
**Decided:** `canonical_item_variants` table rather than the array column the brief sketched.
**Why:** Every human review decision becomes a new known variant, and each variant needs
provenance (who added it, from which submission), a `times_matched` counter, a locale, and its own
`pg_trgm` index. An array column carries none of that and cannot be indexed per-element with trgm.
**Rejected:** `text[]` with a GIN index (cheaper, but loses provenance — which C4-grade auditability
and the review loop both need).

### D-05 — Basket weights and quantities do different jobs
**Decided:** `quantity` drives cost; `weight` drives coverage and the normalised index.
**Why:** "Cost to physically buy this basket" is `Σ qᵢ·pᵢ`. "How much of the basket do we actually
observe" is a weight-weighted share, because a missing 12%-weight infant formula matters far more
than a missing 2%-weight pencil. Conflating them produces a coverage number that lies.

### D-06 — Ground truth lives in a separate Postgres schema
**Decided:** Synthetic ground-truth labels go in schema `qeema_eval`, never in `public`.
**Why:** Phases 5/6/8 need held-out labels, but a label leaking into the published API would be a
credibility failure. A separate schema makes leakage structurally impossible: the API's DB role has
no grant on `qeema_eval`.

### D-07 — Redis 8 under AGPLv3 (constraint tension, resolved)
**Decided:** Pin Redis ≥ 8.0 and elect the AGPLv3 option. No substitution.
**Why:** C1 requires all dependencies be OSI-licensed; Redis 7.4–7.x are RSALv2/SSPLv1, neither
OSI-approved — an apparent contradiction with the brief's mandated Redis. Verified against Redis's
own `LICENSE.txt`: from 8.0 Redis is tri-licensed RSALv2 / SSPLv1 / **AGPLv3**, and AGPLv3 is
OSI-approved. Redis runs as a separate unmodified network service, so AGPLv3 imposes no obligation
on Qeema's Apache-2.0 source.
**Rejected:** Substituting Valkey (would have violated "do not substitute" for no benefit).
Operators who want a permissive licence can set `REDIS_IMAGE` to Valkey — wire-compatible, no code
change.

### D-08 — CPU-only PyTorch wheels in the ML image
**Decided:** Install torch from `https://download.pytorch.org/whl/cpu` in the Docker build.
**Why:** The default linux torch wheel bundles CUDA and is ~800 MB+; the CPU wheel is ~180 MB. The
service does inference on small batches — GPU is irrelevant and the image size is not.

### D-09 — Model weights baked into the image at build time
**Decided:** `RUN python -c "SentenceTransformer('intfloat/multilingual-e5-base')"` during build,
with `HF_HOME` inside the image.
**Why:** C2 says a clean machine runs one command and gets a working system. Downloading 1.1 GB at
first boot makes the demo slow and fails entirely in an air-gapped review environment.
**Cost:** larger image, longer first build. Accepted — the build happens once, the demo happens in
front of reviewers.
**Rejected:** Lazy download at boot (fails the air-gapped case); a volume-mounted cache (a manual step).

---

## 3. Architecture

```
                    ┌─────────── docker compose ───────────┐
  reporter PWA ──┐  │                                       │
  partner CSV  ──┼──┼──▶  app (Laravel 13)  ──HTTP──▶  ml (FastAPI)
  scrapers     ──┘  │       │        ▲                  │  matching
                    │       │        │                  │  anomaly
   public API   ◀───┼───────┤        │                  │  nowcast
   dashboard    ◀───┼───────┘        │                  └── local open weights
                    │                │                       (no network at runtime)
                    │   worker (Horizon queues) ─────────┘
                    │       │
                    │   postgres 16 (pgvector, pg_trgm)   redis 8
                    └───────────────────────────────────────┘
```

The Laravel app never imports an ML library. All inference crosses the HTTP boundary, which is what
makes `ml` independently deployable and separately testable, and what lets PHP tests run without
loading a 1.1 GB model.

**Degradation.** If `ml` is unreachable, the system must not 500. `MlClient` applies a timeout,
bounded exponential retry, and a Redis-backed circuit breaker. On open circuit: submissions land as
`needs_review` instead of auto-resolving; the index computes from observed data only and reports the
reduced coverage honestly. Degraded ≠ broken.

---

## 4. Data model

Postgres 16. `timestamptz` everywhere. Money as `numeric`, never float.

### 4.1 Configuration and reference

**`countries`** — `id`, `code` (ISO-3166-1 alpha-2, unique), `name`, `name_local`,
`currency_code` (ISO-4217), `currency_symbol`, `currency_minor_units`, `default_locale`,
`locales jsonb`, `timezone`, `admin1_label`, `admin2_label`, `fx_config jsonb`, `index_config jsonb`,
`is_active`, timestamps.
Everything country-specific is a row here or in a child table. Nothing is a constant in code (C3).

**`locations`** — `id`, `country_id`, `admin1_name`, `admin1_code`, `admin2_name`, `admin2_code`,
`name`, `name_local`, `slug`, `latitude numeric(9,6)`, `longitude numeric(9,6)`,
`population_estimate bigint`, `is_active`, timestamps.
`unique(country_id, slug)`; index `(country_id, is_active)`.
Lat/lon are used for spatial neighbour features in nowcasting — computed by haversine, not by a
commercial geocoder (C1).

**`units`** — `id`, `code`, `name`, `dimension` (`mass`|`volume`|`count`), `base_unit_code`,
`factor_to_base numeric(18,9)`.
This is what makes "1 kg", "500 g" and "a loaf" comparable. Every price is normalised to price per
base unit before it can enter the index.

**`canonical_items`** — `id`, `country_id`, `code`, `name_en`, `name_local`, `category`,
`default_unit_code`, `default_quantity`, `embedding vector(768)`, `embedding_model`,
`embedding_updated_at`, `is_active`, timestamps.
`unique(country_id, code)`; HNSW index on `embedding` with `vector_cosine_ops`; GIN trgm on
`name_en`, `name_local`.

**`canonical_item_variants`** — `id`, `canonical_item_id`, `text`, `normalized_text`, `locale`,
`source` (`seed`|`human_review`|`scraper`|`partner`), `created_from_submission_id`,
`created_by_user_id`, `times_matched`, timestamps. GIN trgm on `normalized_text`. (See D-04.)

**`baskets`** — `id`, `country_id`, `name`, `version`, `effective_from date`,
`effective_to date NULL`, `is_active`, `notes`, timestamps. `unique(country_id, version)`.

**`basket_items`** — `id`, `basket_id`, `canonical_item_id`, `weight numeric(8,6)`,
`quantity numeric(12,4)`, `unit_code`, `category`, `notes`.
`unique(basket_id, canonical_item_id)`. Weights validated to sum to 1.0 ± 1e-6 per basket.

### 4.2 Ingestion and provenance

The chain is **`submissions` → `resolutions` → `price_observations` → `index_snapshot_items`**.
Raw text is never discarded and never overwritten.

**`sources`** — `id`, `country_id`, `type` (`reporter`|`partner_upload`|`scraper`), `name`, `url`,
`license`, `contact`, `config jsonb`, `is_active`, timestamps.

**`ingestion_batches`** — `id`, `source_id`, `uploaded_by_user_id`, `filename`, `checksum`,
`row_count`, `accepted_count`, `rejected_count`, `status`, `column_mapping jsonb`,
`error_report jsonb`, `started_at`, `finished_at`, timestamps.
`checksum` gives idempotency: re-uploading the same file is a no-op, not a duplicate.

**`reporters`** — `id`, `country_id`, `external_ref uuid`, `display_name`, `location_id`,
`reputation numeric(5,4)`, `reputation_alpha`, `reputation_beta`, `submissions_total`,
`submissions_accepted`, `submissions_rejected`, `first_seen_at`, `last_seen_at`, `is_blocked`,
timestamps. Reputation is the mean of a Beta posterior — see §6.3.

**`submissions`** — `id uuid`, `country_id`, `location_id`, `reporter_id`, `source_id`,
`ingestion_batch_id`, `raw_text`, `raw_price numeric(18,4)`, `currency_code`, `raw_unit`,
`raw_quantity`, `photo_path`, `observed_at timestamptz`, `collected_at timestamptz`,
`ingested_at timestamptz`, `device_metadata jsonb`, `client_idempotency_key`, `status`, timestamps.
`unique(reporter_id, client_idempotency_key)` — this is what makes offline replay safe (§7.1).
Three distinct timestamps because they genuinely differ for an offline submission synced days later.

**`resolutions`** — `id`, `submission_id` (unique), `canonical_item_id`, `method`
(`exact`|`lexical`|`semantic`|`fused`|`human`|`rule`), `confidence numeric(5,4)`,
`candidates jsonb`, `reviewed bool`, `reviewed_by_user_id`, `reviewed_at`, `model_version`,
timestamps.

**`price_observations`** — `id`, `submission_id` (unique), `country_id`, `location_id`,
`canonical_item_id`, `price numeric(18,4)`, `currency_code`, `unit_code`, `quantity`,
`normalized_price_per_base_unit numeric(18,6)`, `observed_on date`, `observed_at timestamptz`,
`reporter_id`, `source_id`, `reputation_at_time numeric(5,4)`, `is_valid bool`,
`superseded_by_id`, timestamps.
Index `(country_id, location_id, canonical_item_id, observed_on)` — the hot path for index
computation. Corrections supersede rather than mutate, preserving history.

**`anomaly_scores`** — `id`, `submission_id`, `score numeric(5,4)`, `verdict`
(`clean`|`suspect`|`rejected`), `reasons jsonb`, `layer_scores jsonb`, `model_version`, `created_at`.

**`fx_rates`** — `id`, `country_id`, `rate_date date`, `official_rate numeric(18,8)`,
`parallel_rate numeric(18,8)`, `source`, `fetched_at`, `is_manual bool`, `raw jsonb`.
`unique(country_id, rate_date, source)`.

### 4.3 Output

**`index_snapshots`** — `id`, `country_id`, `location_id`, `basket_id`, `snapshot_date date`,
`cost_local numeric(18,4)`, `cost_usd numeric(18,4) NULL`, `coverage_pct numeric(5,4)`,
`imputed_share numeric(5,4)`, `ci_low_local`, `ci_high_local`, `normalized_index numeric(10,4)`,
`fx_rate_used`, `fx_rate_type`, `fx_rate_date`, `fx_is_stale bool`, `observed_item_count`,
`total_item_count`, `is_stale bool`, `computed_at`, `model_version`, timestamps.
`unique(location_id, basket_id, snapshot_date)` — makes recomputation an idempotent upsert.

**`index_snapshot_items`** — `id`, `index_snapshot_id`, `canonical_item_id`,
`unit_price_local numeric(18,6)`, `weight`, `quantity`, `contribution_local`, `is_imputed bool`,
`imputation_method`, `ci_low`, `ci_high`, `observation_count`, `source_observation_ids jsonb`.
`is_imputed` originates here and is carried unchanged through every API response and every UI
element (C6, §8.4).

**Volume.** 20 locations × 1 basket × 183 days ≈ 3,660 snapshot rows and ~55k item rows for the
6-month demo. Trivial. Partitioning is **not** warranted at demo scale; `docs/adr/0005-scale.md`
records the threshold (~10M item rows) at which monthly range partitioning on `snapshot_date`
becomes worthwhile.

### 4.4 Evaluation (separate schema)

Schema `qeema_eval`, populated only by the synthetic generator, never read by the API.
**`gt_submissions`** — `submission_id`, `true_canonical_item_id`, `true_price_per_base_unit`,
`is_erroneous`, `is_manipulated`, `error_type`.
**`gt_prices`** — `location_id`, `canonical_item_id`, `date`, `true_price_per_base_unit`.
The latter is the held-out target for nowcasting backtests (§6.4) — it contains the true price even
on days with zero observations, which is exactly what makes imputation measurable.

---

## 5. Country configuration

One YAML file per country in `countries/`. This is the only place a country fact may appear (C3).

```yaml
country:
  code: LY
  name: Libya
  name_local: ليبيا
  currency: { code: LYD, symbol: "د.ل", minor_units: 3 }
  timezone: Africa/Tripoli
  locales: [ar, en]
  default_locale: ar
  admin_labels: { admin1: Municipality, admin2: District }

fx:
  provider: fulus_ly          # fulus_ly | generic_http | manual
  base_currency: USD
  rate_type: parallel         # which rate the index uses
  max_staleness_days: 7
  config: { url: "...", official_path: "$.official", parallel_path: "$.parallel" }

index:
  observation_window_days: 7
  recency_half_life_days: 3
  min_observations_for_ci: 3
  bootstrap_draws: 500
  base_date: 2026-01-01

units: [...]
locations: [...]
canonical_items: [...]        # with seed variants per locale
basket:
  name: Child Affordability Basket
  version: 1
  effective_from: 2026-01-01
  items:
    - { item: infant_formula_400g, weight: 0.12, quantity: 1, unit: pack, category: infant_nutrition }
    # ... 15 items across the nine mandated categories
```

Shipped: `ly.yaml` (default, 15 items) and a second country (§Phase 11) proving genericity.

---

## 6. The statistical core

### 6.1 Per-item price estimate

For location `L`, canonical item `i`, date `D`, over valid observations in
`[D − window, D]` (window from `index.observation_window_days`):

```
ω(o) = exp(−ln2 · (D − observed_on(o)) / half_life) · reputation_at_time(o)
p̂ᵢ(L,D) = weighted_median{ normalized_price_per_base_unit(o) }  with weights ω(o)
```

Weighted **median**, not mean: crisis-market price data is heavy-tailed and contains data-entry
errors that survive anomaly screening. With `n < min_observations_for_ci` the estimate is kept but
the interval is widened; with `n = 0` the value is imputed (§6.4) and flagged.

Using `reputation_at_time` — the reputation as of ingestion, stored on the row — rather than current
reputation keeps recomputation deterministic. Recomputing an old snapshot must not silently change
because a reporter's reputation moved since.

### 6.2 Basket cost, coverage, intervals

```
cost_local(L,D)   = Σᵢ qᵢ · p̂ᵢ(L,D)
cost_usd(L,D)     = cost_local(L,D) / fx_parallel(D)
coverage_pct(L,D) = Σ_{i observed} wᵢ            (weights normalised to 1)
imputed_share     = Σ_{i imputed}  wᵢ
normalized_index  = 100 · cost_local(L,D) / cost_local(L, base_date)
```

**Confidence interval — Monte Carlo, combining both uncertainty sources.** For each of
`bootstrap_draws` iterations: for observed items, resample that item's observations with weights ω
and recompute the weighted median; for imputed items, draw from the nowcast model's predicted
quantile distribution. Recompute the basket cost per draw; take the 2.5th and 97.5th percentiles.

This is the honest construction: an interval from sampling noise alone would understate uncertainty
badly on a snapshot that is 40% imputed. The two sources are combined in one draw, not added after.

**Basket version changes** are chain-linked: on the changeover date both versions are costed, and
the ratio rebases the new series so the published level has no artificial discontinuity.

### 6.3 Anomaly detection and reputation

Three layers, each contributing a human-readable reason:

1. **Hard bounds** — per item, derived from the trailing 90-day distribution across all locations
   (`median × [1/k, k]`, k configurable), not hardcoded thresholds. Self-tuning, so country-agnostic.
2. **Robust statistical outlier** — modified z-score using MAD against the location/item/time
   distribution, with a small-sample fallback to the national distribution.
3. **IsolationForest** over engineered features: price ratio to local median, ratio to national
   median, deviation from the item's recent trend, submission hour, reporter's submission rate,
   reporter's historical deviation, round-number-ness of the price, unit plausibility.

**Reputation** is a Beta-Bernoulli posterior per reporter: `Beta(α₀+accepted, β₀+rejected)` with
`α₀ = β₀ = 2`, reputation reported as the posterior mean. A new reporter starts at 0.5 with wide
uncertainty rather than 0 — a cold-start reporter must not be silenced before they have a record.

**Avoiding the doom loop:** reputation weights the §6.1 estimator, and the anomaly score feeds
reputation. Left unchecked, an unlucky new reporter gets down-weighted, deviates more, and spirals.
Two guards: reputation is floored at `0.25` for weighting purposes, and only *human-confirmed*
verdicts update the posterior — an automated `suspect` verdict alone never does.

### 6.4 Nowcasting

LightGBM quantile regression at τ ∈ {0.1, 0.5, 0.9}, one model per quantile.
Features: haversine-nearest observed neighbours' prices (k=3) with distances, same-admin1 median,
temporal lags (1/7/14/28 d), item trend slope, FX level and lagged change, co-movement of items in
the same category, day-of-week, days-since-last-observation, observation density.

**Backtest:** temporal split, never random — the last 30 days held out. Reported: MAE, MAPE,
pinball loss, and empirical coverage of the nominal 80% interval (an interval that claims 80% and
delivers 55% is worse than no interval). Results committed to `docs/model-cards/nowcasting.md`.

**Cold start.** `docker compose up` must produce a working system before any training run, so the
entrypoint trains on the seeded synthetic history at first boot — fast (seconds, on ~50k rows) and
avoids committing a binary artifact. Until a model exists, imputation falls back to the location's
admin1 median and is flagged `imputation_method = "fallback_admin1_median"`, never silently.

---

## 7. Application surface

### 7.1 Reporter PWA — the offline problem

Livewire is server-driven and cannot function offline. The honest split:

- **Online path:** Livewire 4 components for location/item pickers with server-side search.
- **Offline path:** the submission form itself is **plain Alpine + a JSON endpoint**, not Livewire.
  A service worker (Workbox, MIT) serves the app shell cache-first. Submissions are written to
  IndexedDB with a client-generated UUID `client_idempotency_key`, then replayed via the Background
  Sync API where available and via an online-event + startup flush on iOS Safari, which lacks it.
- `unique(reporter_id, client_idempotency_key)` makes replay idempotent at the database level —
  a duplicate replay is a no-op, so a flaky connection can never inflate the index.
- UI shows per-submission state: pending / syncing / synced / failed.
- Photos are downscaled client-side to ≤1280px JPEG before queueing; on quota exhaustion the photo
  is dropped and the price submission is kept, with the user told.

Target: under 30 s per submission. RTL via CSS logical properties, locale list from country config.

### 7.2 Ingestion

Partner CSV/XLSX via OpenSpout (MIT) in streaming mode — bounded memory on large files. A column
mapping UI persists `column_mapping` on the batch. Validation is per row: a malformed file yields a
downloadable per-row error report, never a 500. Partial success is the norm — good rows land, bad
rows are reported.

Scrapers implement a `PriceScraper` contract, are registered per source, respect `robots.txt`, are
rate-limited and resumable via a cursor on `sources.config`, and are idempotent through a natural
key `(source, external_id, observed_on)`.

### 7.3 Public API (unauthenticated read)

```
GET  /api/v1/countries
GET  /api/v1/locations?country=LY
GET  /api/v1/index/current?country=LY[&location=]
GET  /api/v1/index/history?location=…&from=&to=
GET  /api/v1/index/{location}/{date}          # with per-item breakdown
GET  /api/v1/baskets/current?country=LY
GET  /api/v1/items/{code}/prices?location=&from=&to=
GET  /api/v1/coverage?country=LY
GET  /api/v1/fx?country=LY&from=&to=
GET  /api/v1/export.{csv,json}?…              # streamed
GET  /api/v1/openapi.json  ·  GET /docs
```

Every price-bearing object carries `is_imputed`, `confidence_low`, `confidence_high` and
`observation_count`. Rate limiting is per-IP on the unauthenticated read tier. Responses carry
`ETag` and `Cache-Control`; exports stream so a bulk download cannot exhaust memory.

### 7.4 Dashboard

Map rendering without a commercial provider (C1): **MapLibre GL JS** (BSD-3-Clause) over
OpenStreetMap-derived GeoJSON **bundled with the country config** — no tile server, no account, no
network at runtime. Locations render as choropleth polygons or graduated circles. ODbL attribution
is displayed as the licence requires.

Charts: Apache ECharts (Apache-2.0), imported per-module to keep the bundle small. Server-rendered
first paint, deferred chart hydration, no SPA framework — the Lighthouse ≥90 performance target on a
low-end phone forces this. Imputed points are visually distinct (hatched/dashed) everywhere,
never blended into observed series.

---

## 8. Testing and CI

- **Pest** against real Postgres. SQLite is **not** viable — `pgvector` and `pg_trgm` have no SQLite
  equivalent, and testing the matcher against a different engine than production would be
  self-deception. CI uses a Postgres service container.
- **pytest** with the model faked by default; tests that load real weights are marked `slow` and
  excluded from the default run, so unit tests stay fast and CI does not download 1.1 GB.
- **Contract tests, both directions.** JSON Schemas in `contracts/` are the single source of truth
  for the Laravel↔ML boundary. PHP tests validate the *fake* `MlClient`'s responses against them;
  Python tests validate the *real* FastAPI responses against the same files. The fake therefore
  cannot drift from reality — the usual failure mode of mocked service boundaries.
- **Playwright** against the composed stack, including an offline test that drops the network,
  submits, restores the network and asserts exactly one row landed.
- **Coverage gate ≥80%** on both services from Phase 0, enforced in CI, not deferred.

---

## 9. One-command demo

`docker compose up` on a clean machine must produce a seeded, working system (C2).

- `postgres`: `pgvector/pgvector:pg16`, extensions created by an init script.
- `app`/`worker`: one PHP 8.4 image; Vite assets built at image build time, never at runtime.
- `ml`: Python 3.11-slim, CPU torch (D-08), weights baked in (D-09).
- Entrypoint runs `migrate --force` then seeds, guarded by a Postgres **advisory lock** so `app` and
  `worker` booting concurrently cannot double-seed, and a marker row so a restart is a no-op.
- `depends_on: condition: service_healthy` throughout; every service has a real healthcheck.

`make demo` wraps build + up + a readiness poll. Expected first build ~10–15 min (mostly torch and
weights); subsequent starts seconds.

---

## 10. Phase plan

Phases follow the brief. After each: full test suite green, commit, `PROGRESS.md` updated. No phase
begins with the previous one's tests failing.

0 Foundation · 1 Domain + admin · 2 Synthetic generator · 3 Reporter PWA · 4 Ingestion ·
5 Matching · 6 Anomaly · 7 Index + FX · 8 Nowcasting · 9 Public API · 10 Dashboard ·
11 Self-hosting + 2nd country · 12 Hardening

---

## 11. Open questions

1. **Second country.** Leaning Sudan (SDG, Arabic, severe parallel-market spread, active crisis) —
   it exercises the same RTL and FX-spread paths as Libya while proving nothing is hardcoded. Yemen
   or Lebanon would also work. Not blocking; will default to Sudan and flag on delivery.
2. **Fulus.ly terms.** The adapter scrapes a public page. Robots/ToS to be checked before enabling
   it by default; if unclear, the generic HTTP adapter plus manual entry ships as the default and
   Fulus.ly is opt-in. Being wrong here is a legal problem, not a technical one.
3. **Reporter identity.** Currently an anonymous device UUID. Sufficient for reputation, but offers
   no recovery if a device is lost and no defence against a reporter farming new identities. A
   lightweight phone-less claim code is the likely answer.
4. **Photo storage and PII.** User-submitted photos in a public-data project need an explicit
   retention and redaction policy before any public exposure. Photos are admin-only for now.

---

## 12. Risks

| Risk | Mitigation |
|---|---|
| First build is slow/large (torch + 1.1 GB weights) | CPU wheels (D-08), layer ordering, documented expectation |
| Build host at 95% disk | Monitored; image sizes tracked in PROGRESS.md |
| Livewire 4 / Filament 5 are recent majors | Verify APIs against current docs before use, not from memory |
| Matching accuracy unknown until real Arabic data exists | Synthetic generator emits realistic dialect/misspelling noise; accuracy published honestly, not asserted |
| Imputation quietly dominating a sparse location | `imputed_share` published on every snapshot; UI and API flag it |

---

## 13. Constraint compliance matrix

| Constraint | Where enforced | Verified by |
|---|---|---|
| C1 no proprietary runtime deps | NOTICE, `make licenses` | CI SPDX check; no API keys in any config |
| C2 one-command demo | `docker-compose.yml`, entrypoints | CI boots clean stack and probes API + dashboard |
| C3 country-agnostic | `countries/*.yaml` | CI grep for banned literals outside allowed paths |
| C4 Apache-2.0 end to end | LICENSE/NOTICE/CONTRIBUTING/COC | Present in repo root |
| C5 ≥80% coverage | Pest `--min=80`, pytest `fail_under=80` | CI gate, both services, from Phase 0 |
| C6 public data product | `routes/api.php` unauthenticated | Contract tests vs generated OpenAPI spec |
