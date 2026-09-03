# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Qeema publishes a live, child-weighted affordability index for crisis economies: what it
costs today, in a specific town, to buy what a child needs, priced against the
parallel-market exchange rate. Free-text price reports (Arabic, Latin script, or a mix)
arrive from crowdsourced reporters, partner spreadsheets and public scrapers; they are
resolved to canonical basket items, screened for error and manipulation, gap-filled with
labelled estimates, and published through an unauthenticated API and dashboard.

## The six constraints

These govern nearly every design decision in the repository and each is enforced by a CI
job, not by convention. Breaking one is not a trade-off to weigh — it is a build failure.

- **C1 — No proprietary or paid service in the runtime path.** No hosted inference, no
  commercial geocoder or map tiles, no closed vector DB. Every model runs locally from
  open weights; every dependency is OSI-licensed. `make licenses` regenerates evidence
  from the lockfiles. Paid tools (e.g. a scraping MCP) are dev-time only — commit the
  *findings*, never a pipeline that calls them at runtime.
- **C2 — `docker compose up` is one command.** If a change needs a manual step on a clean
  machine, it is not finished. The `compose` CI job builds the images, boots the stack and
  runs Playwright against it on every PR.
- **C3 — Nothing country-specific in code.** Country, currency, locations, basket, locales
  and FX source live in `countries/*.yaml` and reach the app through the database.
  `infra/scripts/check-country-agnostic.sh` greps `api/app`, `api/config`, `api/routes`,
  `api/database/migrations`, `ml/src` and `infra/docker` for literals like `Libya`, `LYD`,
  `Tripoli`. It searches **untracked** files too, so a new file cannot pass locally and
  fail once committed. Docs, tests, factories and `countries/` are exempt.
- **C4 — Apache-2.0 end to end.** Software Apache-2.0, data CC BY 4.0 (`LICENSE-DATA`).
- **C5 — ≥80% line coverage** in both services, gated in CI.
- **C6 — The data is the product.** The read API and dashboard are genuinely public and
  unauthenticated. Do not add a key, a tier, or an account wall to a read path.

Two more invariants carry the same weight:

- **Imputed values are never disguised as observed.** `is_imputed: true` travels from the
  estimator through the database, API and UI, with a confidence interval that accounts for
  sampling noise *and* imputation uncertainty. `expect(...)->toDeclareImputationStatus()`
  in `api/tests/Pest.php` exists for this.
- **Raw submissions are immutable.** Corrections supersede via `superseded_by_id`; they
  never overwrite. The chain from a published number back to the raw text a person typed
  must stay intact.

## Commands

```bash
make install-hooks # once per clone — pre-push runs the static gates (~6s)
make demo          # build + up + block until healthy — the reviewer path
make lint          # every static gate CI runs, including the constraint checks
make verify        # lint + both suites with their coverage gates
make fix           # pint + ruff --fix + ruff format
make test          # both suites, both coverage gates
make test-php      # Pest, --min=80
make test-ml       # pytest, --cov, fail_under=80
make test-e2e      # Playwright against the running stack
make check-openapi # the drift gate on its own
make licenses      # regenerate docs/LICENSES.md from the lockfiles (C1)
make reseed        # destructive: drop schema, rebuild with demo data
make psql / shell / logs / nuke / up / down / ps / restart
```

**Gates are defined once and invoked by both CI and the hook** — `gate-php-static`
(pint, OpenAPI drift, PHPStan), `gate-ml-static` (ruff, ruff format, mypy),
`gate-constraints` (C3, workflow validity, secret scan). `make lint` is all three.
Adding a gate means adding it to a `gate-*` target; a gate that exists only in
`.github/workflows/ci.yml` is unrunnable locally, and that is exactly how this
build went red on its own twice — a country name in a comment, then a currency
code, neither visible to the command anyone runs while working.

**`infra/scripts/retry.sh <attempts> <command...>` wraps network fetches only.**
Every `composer install`, `npm ci`, `uv pip install` and `playwright install` in
`.github/workflows/ci.yml` goes through it, because a build that dies on someone
else's `HTTP/2 504` is indistinguishable on the dashboard from a real failure.
Never wrap a *test* in it: a retried test hides a flake, and flakes here are
treated as defects that must stay visible.

Single tests:

```bash
cd api && ./vendor/bin/pest tests/Feature/Pipeline/ResolveSubmissionJobTest.php
cd api && ./vendor/bin/pest --filter "supersedes"
cd ml  && .venv/bin/python -m pytest tests/test_packsize.py::test_name -q
cd e2e && npx playwright test tests/loop.spec.ts
```

Artisan surface (`docker compose exec app php artisan <name>`), since the make
targets only cover a third of it:

```
qeema:bootstrap [--force --fresh --skip-demo]  migrate + seed; behind make seed/reseed
qeema:config:import                            apply a countries/*.yaml edit to a running deployment
qeema:index / qeema:index:publish              recompute stale snapshots, then publish
qeema:index:link                               --country
qeema:pipeline:sweep / qeema:pipeline:health   adopt missed submissions · report whether "live" is true
qeema:review:rematch                           re-run the matcher over the review queue
qeema:fx:fetch [--country] / qeema:scrape      the two inbound feeds
qeema:import:file <path> --source=<slug>       a partner spreadsheet from a shell (--map field=Header, --dry-run)
qeema:nowcast:train                            retrain the imputation model
qeema:corpus:promote                           corpus wording → catalogue variant (see the trap below)
qeema:demo:scale --country                     build a load dataset from countries/corpus/
qeema:reporters:bias / qeema:reporter:forget / qeema:retention:enforce
qeema:openapi [--check]                        regenerate or gate public/openapi.json
qeema:scheduler:heartbeat
```

`qeema:bootstrap` has **no `--country`** — it seeds every `countries/*.yaml` it
finds. (The `--country=VE` in the header comment of `countries/ve.yaml` is stale
and does nothing.) Per-country options exist on `fx:fetch`, `index:link`,
`reporters:bias` and `demo:scale`.

**`public/openapi.json` is generated, never edited.** The source is the PHP
attributes in `api/app/Support/OpenApi/`; `qeema:openapi` scans them and writes
the file, and `--check` fails the build on any difference. Hand-editing the JSON
gets reverted by the next regeneration and reads as drift in the meantime.

Toolchain notes:

- PHP static analysis **needs `--memory-limit=1G`**. CI sets `memory_limit=-1` so it never
  hit this; a stock PHP crashes the PHPStan worker with a php.ini message instead of
  reporting analysis. The Makefile passes the flag; do too if invoking directly.
- Python runs from `ml/.venv` (`uv venv --python 3.11 .venv && uv pip install -e ".[dev]"`).
  There is no `pytest` on PATH — always `.venv/bin/python -m pytest`.
- CI runs pytest with `-m "not slow"`. The `slow` marker is for tests that download ~1.1 GB
  of weights, and no nightly workflow exists, so anything marked slow runs only by hand.
- `make verify` covers every CI gate that does not need Docker: both linters, PHPStan,
  mypy, both suites with their coverage gates, the C3 grep, the workflow check, the
  OpenAPI drift check and the secret scan. The single exception is the compose job
  (image build, stack boot, Playwright), which is `make test-e2e` against a running
  stack.

**This machine's ports are not the documented ones.** The local `.env` (gitignored) sets
`APP_PORT=8090` and `ML_PORT=8001`, so the stack is at `http://localhost:8090`, not the
8080 the README advertises. `ML_PORT` is moved because 8000 collides with `php artisan serve`.

The Makefile does **not** read `.env` — `APP_URL ?= http://localhost:8080` is its own
default — so `make demo` and `make wait` poll 8080, spin for ten minutes against a stack
that is already healthy on 8090, then dump logs and exit 1. Pass the port explicitly:
`make demo APP_URL=http://localhost:8090`. Compose is unaffected; `docker-compose.yml`
derives `APP_URL` from `APP_PORT` itself.

## Architecture

```
app (Laravel 13 / PHP 8.4)  ──HTTP──▶  ml (FastAPI, local open weights)
  ├─ public API · dashboard · Filament admin (/admin) · reporter PWA (/report)
  ├─ worker (Horizon) · scheduler          ├─ matching   e5 + pg_trgm + rapidfuzz
  └─ postgres 16 + pgvector · redis 8      ├─ anomaly    bounds + robust stats + IsolationForest
                                           └─ nowcast    LightGBM quantile regression
```

**The Laravel app never imports an ML library.** Every inference crosses HTTP through
`MlClientInterface` (`MlClient` real, `FakeMlClient` for tests). This keeps the ML service
independently deployable and lets the PHP suite run without a gigabyte of weights.

### The pipeline, end to end

`RecordSubmission` → `ResolveSubmissionJob` → `ResolveSubmission` (calls the matcher) →
`Resolution` → `PriceObservation` → `PriceObservationObserver` marks affected
`IndexSnapshot`s stale → `qeema:index` (recompute, every minute) → `qeema:index:publish`.

Properties that matter more than speed, and that changes must preserve:

- `ResolveSubmissionJob` is **idempotent** — it no-ops unless the submission is still
  `pending`, and `price_observations.submission_id` is UNIQUE underneath.
- An ML outage is **waited out**, not converted into human review work (backoff ladder).
- Nothing ends in silence: a submission the pipeline cannot process goes to a reviewer with
  the error attached.
- `PipelineSweepCommand` (every minute) adopts anything the dispatch-on-write path missed.
- `routes/console.php` is what makes "live" a property of the deployment. Every scheduled
  task uses `withoutOverlapping(<explicit expiry>)` + `onOneServer()` + `runInBackground()`;
  Laravel's default day-long overlap lock would make a stopped pipeline look idle.

### Country configuration is the only country-specific surface

`countries/<code>.yaml` carries `country`, `fx`, `index`, `units`, `locations`,
`canonical_items`, `basket`, `sources`, `demo`. `CountryConfigImporter` loads it into the
database (`php artisan qeema:config:import`). Adding a country is a new YAML file plus
`make reseed` — if it needs a code change, that is a bug in the abstraction.

**Two countries are configured, and the second one is the actual regression surface.**
`countries/ve.yaml` exists to prove C3, not to assert expertise: it is left-to-right,
Spanish, Latin script (so the Arabic normalisation path is bypassed rather than
exercised), built on a different staple, two-decimal currency against the other's three,
and Western-hemisphere with negative longitudes that the map projection and the date
bucketing both have to survive. The C3 grep catches *literals*; it cannot catch an
assumption about script direction, decimal places or sign. A change that works only for
the default country still passes lint — check it against both, and read the header of
`ve.yaml`, which lists what each axis was chosen to break.

Inside `demo.reference_price_provenance`, every cited price needs a URL, a date and what the
source actually said; `demo.minimum_sourced_prices` is a **ratchet** — raise it when adding
citations, never lower it to make a test pass. See
`api/tests/Feature/Country/ReferencePriceProvenanceTest.php`.

## Traps specific to this repository

**The corpus is not the catalogue.** `countries/corpus/<code>.json` is *reporter
simulation* — wordings used to generate synthetic traffic and to test the matcher.
The matcher's actual vocabulary is `countries/<code>.yaml` → `canonical_items[].variants`.
Adding wordings to the corpus changes no matching score whatsoever. `qeema:corpus:promote`
moves reviewed corpus wordings into the catalogue (and refuses ones under `hold`, filed
under two items, or also listed as distractors). Promotion has a cost the command prints:
a promoted wording stops being a test and becomes memorisation.

**Two normalisers must agree exactly.** `App\Support\Text\TextNormalizer` and
`qeema_ml.matching.normalise` are deliberate duplicates — seeding must work without the ML
service, and Postgres trigram queries need the normalised form in SQL. Drift between them
is nearly invisible and silently destroys matching. Both are tested against
`contracts/text-normalisation.json` (22 hand-written fixtures) and
`contracts/text-normalisation-corpus.json` (3,887 real strings). Change one, change both,
and run both suites.

**Contracts stop the fake drifting from reality.** JSON Schemas in `contracts/` are
validated by the PHP tests (against `FakeMlClient`) *and* the Python tests (against the real
service). Change a request or response shape and change the schema — that is the mechanism.

**Variants have structural rules.** A bare head noun that names two basket items must not be
the exclusive property of one (one item owning a shared head noun once caused 72% of matcher
errors). `api/tests/Feature/Country/CatalogueVariantPlacementTest.php` guards this and the
adjacent failure of a wording landing under the wrong item. Note that some items use inline
flow style — `variants: [a, b]` — which defeats naive line-based YAML editing; verify
placement after any scripted edit. `ml/scripts/merge_harvest.py` exists because of exactly
that: a throwaway inserter that matched the line `variants:` silently skipped the two items
written in flow style. Use it to merge harvested wordings rather than writing another one.

**Tests need real PostgreSQL** with `vector` and `pg_trgm` (`qeema_test`, port 5432 on the
host). SQLite provides neither. `phpunit.xml` **forces** `DB_DATABASE=qeema_test` because
running the suite inside the app container otherwise bound it to the live dev database.
`QUEUE_CONNECTION=null` there is deliberate: a test that wants the pipeline says so with
`Queue::fake()` or `dispatchSync()`. `QEEMA_ML_URL=http://ml.invalid` — no test may reach a
real ML service.

**Measurement honesty is a project value, not a style.** PROGRESS.md is kept accurate rather
than aspirational; if something is not listed as verified there, assume it does not work.
Matcher figures are reported on **unseen** wordings, with the full-set number beside them,
because promoted variants exact-match and short-circuit before any model runs. When changing
anything statistical, report what the numbers did before and after — and re-run the baseline
rather than quoting a stored one (a fine-tuning result survived for weeks only because it
was measured against a stale `/tmp` snapshot nobody could reproduce).

**The baseline is a script, so re-running it is cheap — run it.** From `ml/`, with
`.venv/bin/python`:

- `scripts/real_text_evaluation.py` — **the headline matcher number.** Scores the matcher
  against product names collected from a government price bulletin and merchant
  catalogues, i.e. text no language model wrote, with the bulletin's cement, rebar and
  feed lines serving as hard negatives. Reads `ml/data/real-text/*.json` (or paths given as
  argv); `QEEMA_EVAL_COUNTRY` picks the catalogue, `QEEMA_MAX_MISSES` how many misses to
  print. Reports unseen-only and full-set separately — quote the unseen one.
- `scripts/embedding_finetune.py` · `scripts/verifier_experiment.py` — the two experiments
  behind the current architecture. Both are cross-validated and both document, in the
  module docstring, the measurement that motivated them.
- `scripts/merge_harvest.py` — merges a harvest into the catalogue *and* the eval set.

Published figures live in `docs/model-cards/`; those files and PROGRESS.md are what to
update when a number moves, and the docstrings are where the reasoning goes.

**Never write evaluation inputs or outputs to `/tmp`.** Three separate scripts once defaulted
there and each produced a result that could not be reproduced. Build from repo paths
(`ml/data/`, `countries/`) with env-var overrides.

## Conventions

- Every source file starts with `// SPDX-License-Identifier: Apache-2.0`.
- Commit subjects are **one sentence, imperative, no watermark or trailer**, describing what
  changed and often what it measured: *"Stop one item owning a head noun two items share,
  which was causing 72 percent of matcher errors"*.
- Comments here explain *why*, and frequently record the incident that motivated the code.
  Match that register — a comment that only restates the line is out of place.
- Larastan level 6, `disallow_untyped_defs` in mypy, `declare(strict_types=1)` throughout.

## Documentation map

`PLAN.md` (schema, formulas, decision log) · `PROGRESS.md` (honest build state) ·
`docs/assessment.md` (proven vs simulated vs not built) · `docs/operations.md` ·
`docs/deployment.md` · `docs/pilot.md` · `docs/do-no-harm.md` (how this platform could hurt
people while working correctly — read before touching anything that handles reporter data) ·
`docs/privacy.md` · `docs/data-sources.md` · `docs/dpg-standard.md` ·
`docs/scale-testing.md` · `docs/libyan-dialect-brief.md` (the linguistic reasoning behind
the catalogue's wordings) · `docs/plan-close-the-loop.md` · `docs/plan-chain-linking.md` ·
`docs/adr/` · `docs/model-cards/` (published matcher, anomaly and nowcast figures).
