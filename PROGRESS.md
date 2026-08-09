# Progress

Kept accurate rather than aspirational. If something is not listed as verified
here, assume it does not work yet.

**Last updated:** 2026-08-09

---

## Where the build is

| Phase | State |
|---|---|
| 0 — Foundation | **Complete and verified** |
| 1 — Domain model + admin | **Partial** — schema, models, factories and tests done; Filament admin and country-config seeding not started |
| 2–12 | Not started |

---

## Phase 0 — verified

The headline requirement works. On this machine, from a clean checkout:

```
docker compose build && docker compose up -d
```

brings up five services, all reporting healthy:

```
SERVICE    STATUS
app        Up (healthy)
ml         Up (healthy)
postgres   Up (healthy)
redis      Up (healthy)
worker     Up (healthy)
```

**What was actually verified, not assumed:**

- `GET /api/v1/health` returns `status: ok` and confirms both `vector` and
  `pg_trgm` are live in the running database — the healthcheck asserts the
  extensions exist, not merely that a connection opens.
- All 18 migrations apply to the containerised Postgres: 26 tables in `public`,
  2 in the isolated `qeema_eval` schema.
- The ML service loads `intfloat/multilingual-e5-base` **offline**
  (`HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`) from weights baked into the
  image at build time, and reports `models_loaded: true`.
- A real cross-lingual embedding round-trip works. Arabic
  `"حليب أطفال ٤٠٠ غرام"` scores **0.875** against `"Infant formula 400g"` and
  **0.808** against `"Cooking oil 1L"`.
- `migrate:fresh` is idempotent across repeated runs.
- Constraint C3 check passes: no country-specific literal in application source.
- Licence inventory generates from both lockfiles with **zero** `UNKNOWN` rows.

**Image sizes:** `qeema-ml` 5.68 GB, `qeema-app` / `qeema-worker` 1.16 GB each.
First build ≈ 35 min on 4 vCPU; subsequent starts are seconds.

---

## Quality gates — current numbers

| Gate | Result |
|---|---|
| Pest | **90 passed**, 133 assertions |
| PHP coverage | **81.2%** (gate ≥80%) |
| pytest | **21 passed** |
| Python coverage | **100%** (gate ≥80%) |
| PHPStan (larastan, level 6) | **0 errors** |
| Pint | clean |
| ruff + mypy | clean |
| Country-agnostic check | pass |

---

## Phase 1 — what exists

**Schema (18 migrations, applied and verified).** All entities from PLAN.md §4,
including the `vector(768)` column with an **HNSW** index over
`vector_cosine_ops`, three **GIN trigram** indexes for lexical matching, and the
`qeema_eval` ground-truth schema held separately so a label cannot leak into a
published response.

**Models (17).** With the domain logic that matters: unit normalisation, the
recency-and-reputation estimator weight, Beta-posterior reputation with a
recovery floor, basket versioning, haversine neighbours, supersede-don't-mutate
corrections, and snapshot quality labelling.

**Factories (17).** With states built for the cases that are hard to test:
sparse snapshots, imputed items with honestly wider intervals, offline
submissions synced days late, unreliable reporters, partially-rejected partner
files, unnormalised Arabic variants.

**Tests worth having, not just coverage.** Hand-computed expected values for
unit conversion and weight decay; proof that a superseded observation keeps its
original price; proof that the offline idempotency key is enforced by a database
constraint rather than application code; proof that imputed values carry wider
intervals than observed ones.

### Still to do in Phase 1

- Filament admin resources for every entity.
- `countries/ly.yaml` and the `CountryConfigSeeder` that loads it.
- `DemoDataSeeder` (the Phase 2 generator).

`BootstrapCommand` currently tolerates both seeders being absent and boots to an
empty-but-serving deployment. That is deliberate for now, but it means
**`docker compose up` currently yields a working system with no data in it.**
Constraint C2 is only half met until Phase 2 lands.

---

## Decisions taken (full rationale in PLAN.md §2)

- **Latest stable majors**: Laravel 13.24, Filament 5.7.6, Livewire 4.3.5,
  Pest 5.0.4. The brief named older majors but granted "or latest stable";
  Filament 5 transitively pins Livewire 4, so the two move together. Confirmed
  with the user before proceeding.
- **swagger-php over Scramble** for OpenAPI: Scramble 0.12 does not support
  Laravel 13 and Composer refuses to resolve it.
- **Redis 8 under AGPLv3.** An apparent conflict between "use Redis" and "all
  dependencies OSI-licensed" — Redis 7.4–7.x are RSALv2/SSPL, neither
  OSI-approved. Verified against Redis's own `LICENSE.txt`: from 8.0 Redis is
  tri-licensed including AGPLv3, which **is** OSI-approved. No substitution
  needed. See `docs/adr/0002-redis-licensing.md`.
- **No map tile provider at all** — MapLibre GL JS over bundled ODbL GeoJSON.
  See `docs/adr/0003-map-rendering.md`.

---

## Known issues and risks

**The `ml` image is 5.68 GB.** Larger than it should be even with CPU-only
torch. Worth attacking before Phase 12 — ONNX Runtime instead of torch is the
obvious lever, at the cost of some complexity.

**Raw e5 cosine similarities discriminate weakly.** The observed gap between a
correct and an incorrect match was only 0.067. This validates the plan's
decision to calibrate confidence and fuse with a lexical signal rather than
threshold raw scores — but it means the auto-resolve threshold in `config.py`
(0.85) is a placeholder that must be set from the Phase 5 evaluation harness,
not guessed.

**Trigram similarity on unnormalised Arabic is poor.** `حليب اطفال` vs
`حليب أطفال` — differing by one alef hamza — scores **0.571**. Confirms the
Arabic normalisation in Phase 5 is load-bearing rather than cosmetic.

**Build host disk pressure.** This machine ran to 99% full during the image
build; recovered to 14 GB by clearing a 9.4 GB npm cache. Not a defect in the
project, but a full build needs roughly 15 GB of headroom.

**CI has never run.** The GitHub Actions workflow is written and mirrors the
local gates, but there is no remote yet, so it is unverified.

**Nothing about the ML service is integrated with Laravel yet.** `MlClient`,
the circuit breaker and the contract tests in `contracts/` are designed in
PLAN.md but not written.

---

## What a reviewer can do right now

```bash
make demo          # build, start, wait for health
curl localhost:8080/api/v1/health
make verify        # lint + both suites + constraint checks
```

The dashboard, public API endpoints beyond `/health`, admin panel and reporter
PWA do **not** exist yet.
