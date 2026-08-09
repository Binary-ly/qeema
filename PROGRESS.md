# Progress

Kept accurate rather than aspirational. If something is not listed as verified
here, assume it does not work yet.

**Last updated:** 2026-08-09

---

## Where the build is

| Phase | State |
|---|---|
| 0 — Foundation | **Complete and verified** |
| 1 — Domain model + admin | **Complete and verified** |
| 2 — Synthetic data generator | **Complete and verified** |
| 3–12 | Not started |

---

## Quality gates — current numbers

| Gate | Result |
|---|---|
| Pest | **165 passed**, 1 skipped, 1,117 assertions |
| PHP coverage | **97.2%** (gate ≥80%) |
| pytest | **21 passed** |
| Python coverage | **100%** (gate ≥80%) |
| PHPStan (larastan, level 6) | **0 errors** |
| Pint / ruff / mypy | clean |
| Country-agnostic check (C3) | pass |
| Licence inventory (C1) | 0 `UNKNOWN` rows |

---

## Phase 0 — verified

`docker compose up` on a clean checkout brings up five healthy services with no
manual steps.

- `GET /api/v1/health` returns `ok` and **asserts `vector` and `pg_trgm` are
  live** — a Postgres missing them accepts connections and then fails every
  match query, so the healthcheck checks the extensions themselves.
- The ML service loads `intfloat/multilingual-e5-base` **offline**
  (`HF_HUB_OFFLINE=1`) from weights baked into the image at build time.
- Cross-lingual retrieval verified end to end: Arabic
  `"حليب أطفال ٤٠٠ غرام"` scores **0.875** against `"Infant formula 400g"` and
  **0.808** against `"Cooking oil 1L"`.
- `migrate:fresh` is idempotent across repeated runs.

**Image sizes:** `qeema-ml` 5.68 GB, `qeema-app` / `qeema-worker` ~1.2 GB each.

---

## Phase 1 — verified

**Schema — 18 migrations.** All entities from PLAN.md §4, including a
`vector(768)` column with an **HNSW** index over `vector_cosine_ops`, three
**GIN trigram** indexes, and the `qeema_eval` ground-truth schema held
physically apart from anything the API can reach.

**Models — 17**, carrying the domain logic that matters: unit normalisation,
recency-and-reputation estimator weighting, Beta-posterior reputation with a
recovery floor, basket versioning, haversine neighbours, supersede-don't-mutate
corrections, snapshot quality labelling.

**Country configuration.** `countries/ly.yaml` holds every Libya fact:
16 locations, 18 canonical items with **133 seeded name variants**, a 15-item
child-weighted basket spanning all nine mandated categories, units, FX and
estimator settings. The loader validates thoroughly and reports **every**
problem at once — unknown item references, unknown units, undefined base units,
half-specified coordinates, duplicate slugs, a default locale not in the locale
list, and weights that do not sum to 1.0 (naming the size of the error).
Importing is idempotent: re-running applies edits without duplicating rows.

**Arabic text normalisation.** Strips harakat and tatweel, unifies alef, taa
marbuta, alef maksura and hamza carriers, folds both Arabic-Indic digit blocks
to ASCII, and normalises punctuation and case. Driven by shared fixtures in
`contracts/text-normalisation.json` so the PHP and Python implementations
**cannot silently drift**.

Measured effect: `حليب اطفال` vs `حليب أطفال` — one hamza apart — scored
**0.571** trigram similarity before normalisation. After, the query matches at
**1.000**.

**Filament 5 admin.** Resources for all 17 entities, each with list, create,
view and edit pages, all rendered under test. `User` implements `FilamentUser`
— Filament denies panel access outside `local` by default, which would have
locked every real deployment out of its own admin panel.

---

## Phase 2 — verified

A seeded, country-config-driven generator producing a six-month history:

```
183 days x 16 locations x 15 items
  -> 11,212 submissions (10,675 observations, 537 queued for review)
     523 erroneous and 80 manipulated, labelled
     43,920 ground-truth cells
```

**The price model** reproduces the four things that make crisis prices hard:
compounding inflation; a currency that moves and passes through to imported
goods **with a 21-day lag, weighted by import intensity** (0.90 for medicines,
0.10 for local produce); a structural periphery premium; and supply shocks that
arrive suddenly and decay slowly. Plus Ramadan food demand, school-term spikes
and winter fuel.

**Realistic messiness, deliberately.** Coverage gaps are structural — 7% of
(location, item) pairs are never observed anywhere, and 8% of location-weeks are
blank. Raw text carries dropped hamza, Arabic-Indic digits, dialect, Latin brand
names inside Arabic phrases, and typos, because a matcher evaluated on clean
catalogue names proves nothing.

**Four kinds of honest mistake**, all present and labelled: `unit_confusion`,
`decimal_slip`, `wrong_currency`, `stale_copy`. Plus a **coordinated bad-actor
cluster** — reporters in two locations reporting 22–38% low, each figure
individually plausible, the pattern visible only across the cluster. That is
the case a per-observation outlier test cannot catch and the reputation layer
has to.

**The answer key stays private.** Ground truth lives in `qeema_eval`, tested to
be absent from `public`, and `price_observations` is asserted to carry no
`true_price`, `is_erroneous` or `is_manipulated` column.

---

## Decisions taken (full rationale in PLAN.md §2)

- **Latest stable majors**: Laravel 13.24, Filament 5.7.6, Livewire 4.3.5,
  Pest 5.0.4. Confirmed with the user; Filament 5 transitively pins Livewire 4.
- **swagger-php over Scramble** — Scramble 0.12 does not support Laravel 13.
- **Redis 8 under AGPLv3.** An apparent conflict between "use Redis" and
  "all dependencies OSI-licensed": Redis 7.4–7.x are RSALv2/SSPL, neither
  OSI-approved. Verified against Redis's own `LICENSE.txt` — from 8.0 Redis is
  tri-licensed including AGPLv3, which **is** OSI-approved. No substitution
  needed. `docs/adr/0002-redis-licensing.md`.
- **No map tile provider at all** — MapLibre GL JS over bundled ODbL GeoJSON.
  `docs/adr/0003-map-rendering.md`.

---

## Bugs found and fixed along the way

- **`migrate:fresh` left `qeema_eval` behind**, so the next migration failed on
  "relation already exists". Would have broken CI and `make reseed`.
- **A promoted `$file` property on an exception segfaulted PHP.**
  `CountryConfigException` shadowed `Exception::$file`, an engine-managed
  property — PHP crashed with no output rather than raising an error.
- **`env()` in a seeder returns null once config is cached**, and the container
  entrypoint caches before seeding. Moved to `config/qeema.php`.
- **Horizon needs `ext-pcntl`**, absent from the official PHP image.
- **Unit tests leaked database rows** — only the Feature suite had
  `RefreshDatabase`, so factory writes from "unit" tests surfaced as unrelated
  unique-constraint failures.
- **`Collection::shuffle()` takes no seed**, so the bad-actor cluster was not
  reproducible despite the surrounding code being seeded.

---

## Known issues and risks

**The `ml` image is 5.68 GB.** Larger than it should be even with CPU-only
torch. ONNX Runtime instead of torch is the obvious lever before Phase 12.

**Raw e5 cosine similarities discriminate weakly** — a 0.067 gap between a
correct and an incorrect match. The `0.85` auto-resolve threshold in `config.py`
is a placeholder that **must** be set from the Phase 5 evaluation harness rather
than guessed.

**CI has never run.** The workflow is written and mirrors the local gates, but
there is no remote yet, so it is unverified.

**No Laravel↔ML integration yet.** `MlClient`, the circuit breaker and the
service contract tests are designed in PLAN.md but not written. The synthetic
generator pre-resolves submissions rather than calling the matcher, so nothing
currently crosses the HTTP boundary at runtime.

**Fulus.ly is not enabled by default.** Its terms have not been reviewed, and
shipping a scraper enabled-by-default against a third party's site is not
something to do casually. Manual FX entry is the default; see PLAN.md §11.

---

## What a reviewer can do right now

```bash
make demo                                    # build, start, wait for health
curl localhost:8080/api/v1/health
open http://localhost:8080/admin             # admin@qeema.local / qeema-demo
make verify                                  # lint + both suites + constraint checks
```

The admin panel is populated with six months of synthetic data across 16
locations, including a review queue with ~537 low-confidence resolutions.

**Not built yet:** the public API beyond `/health`, the dashboard, the reporter
PWA, partner ingestion, and all three ML components (matching, anomaly scoring,
nowcasting) — the ML service currently exposes only health, readiness and model
info.
