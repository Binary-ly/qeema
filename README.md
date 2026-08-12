<div align="center">

# Qeema · قيمة

**A live, child-weighted affordability index for crisis economies.**

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Coverage gate](https://img.shields.io/badge/coverage-%E2%89%A580%25-brightgreen.svg)](#testing)
[![No paid APIs](https://img.shields.io/badge/runtime%20deps-100%25%20OSI-brightgreen.svg)](docs/LICENSES.md)

</div>

---

Official inflation statistics in a crisis economy are usually late, national, and
priced at an official exchange rate nobody can actually buy at. None of that helps
a humanitarian agency decide what a cash transfer should be worth in a particular
town this week.

Qeema measures something narrower and more useful: **what it costs, today, in this
specific place, to buy the things a child needs** — infant formula, staple grains,
paediatric medicines, school materials, hygiene products, cooking fuel, drinking
water — priced against the **parallel-market** exchange rate people really transact
at.

It collects prices from crowdsourced reporters, partner organisations and public
web sources; resolves messy free-text (in Arabic, Latin script, or a mix) to
canonical basket items; screens out errors and manipulation; fills gaps with
explicitly-labelled estimates; and publishes everything through a public API and
dashboard.

**The data is the product.** The API is unauthenticated. The dashboard is free.
Everything is Apache-2.0.

---

## Deploying

See [docs/deployment.md](docs/deployment.md) — requirements, configuration
reference, adding a country, backup and restore, and upgrades.

## Run it

You need Docker. That is the entire list.

```bash
git clone https://github.com/<org>/qeema.git
cd qeema
docker compose up
```

That builds the stack, migrates the schema, seeds a country configuration, and
generates a **six-month synthetic price history** so the system is immediately
demonstrable — before a single real reporter exists. No manual steps.

| | |
|---|---|
| Dashboard | http://localhost:8080 |
| Public API | http://localhost:8080/api/v1/health |
| API docs | http://localhost:8080/docs |
| Admin | http://localhost:8080/admin |
| ML service | http://localhost:8000/ready |

First build takes roughly 10–15 minutes, most of it downloading PyTorch and the
embedding weights, which are **baked into the image** so the system works offline
afterwards. Later starts take seconds.

`make demo` does the same thing and waits until the stack is actually healthy.

---

## What is inside

```
app (Laravel 13) ──HTTP──▶ ml (FastAPI, local open weights)
  │                             ├─ product matching   (multilingual-e5 + pg_trgm + rapidfuzz)
  │                             ├─ anomaly detection  (bounds + robust stats + IsolationForest)
  │                             └─ nowcasting         (LightGBM quantile regression)
  ├─ public API · dashboard · admin · reporter PWA
  └─ worker (Horizon) · postgres 16 + pgvector · redis 8
```

The Laravel application **never imports an ML library**. Every inference call
crosses an HTTP boundary, which keeps the ML service independently deployable and
lets the PHP test suite run without loading a gigabyte of weights.

---

## Design commitments

These are the things this project refuses to compromise on. Each is enforced by a
check in CI, not just a promise in a README.

**No proprietary or paid service, anywhere in the runtime path.** No hosted
inference API, no commercial geocoder, no paid map tiles. Every model runs locally
from open weights; every dependency is OSI-licensed. `make licenses` generates the
evidence from the lockfiles. You can redeploy this whole platform with no
commercial accounts of any kind.

**Country-agnostic.** Nothing about the default country is in the code. Country,
currency, locations, basket composition, locales and FX source all live in
`countries/*.yaml`. Adding a country is a config file, not a patch. CI greps the
source tree to keep it that way.

**Imputed values are never disguised as observed ones.** Sparse coverage is the
normal condition in a crisis, so the system estimates missing prices — and every
estimate carries `is_imputed: true` from the estimator through the database, the
API and the UI, alongside a confidence interval that accounts for *both* sampling
noise and imputation uncertainty. A number that was guessed will always say so.

**Raw submissions are immutable.** Every published figure traces back to the
original raw text somebody typed. Corrections supersede; they never overwrite.

**Degradation, not failure.** If the ML service is unreachable, submissions queue
for human review and the index is computed from observed data with the reduced
coverage reported honestly. The platform gets less confident, not broken.

---

## Testing

```bash
make verify      # everything CI runs
make test-php    # Pest, 80% coverage gate
make test-ml     # pytest, 80% coverage gate
make test-e2e    # Playwright, including the offline-sync path
```

Tests run against **real PostgreSQL** with pgvector and pg_trgm. SQLite cannot
host either, and testing the matcher against a different engine than production
would only prove the wrong thing.

The Laravel↔ML boundary is covered by contract tests: JSON Schemas in
`contracts/` are validated by *both* sides, so the fake used in PHP tests cannot
drift from what the real Python service returns.

---

## Documentation

| | |
|---|---|
| [docs/assessment.md](docs/assessment.md) | **What is proven, what is measured only against a simulation, and what is not built** |
| [docs/operations.md](docs/operations.md) | Running it: what happens on its own, and what to do when it stops |
| [PLAN.md](PLAN.md) | Technical plan, schema, formulas, decision log |
| [PROGRESS.md](PROGRESS.md) | Current build state, honestly reported |
| [docs/adr/](docs/adr/) | Architecture decision records |
| [docs/model-cards/](docs/model-cards/) | Training data, metrics and limitations for each ML component |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development setup and the rules that are not negotiable |
| [SECURITY.md](SECURITY.md) | Threat model and private disclosure |

---

## Status

Under active development. See [PROGRESS.md](PROGRESS.md) for exactly what works
today and what does not — it is kept accurate rather than aspirational.

## Licence

[Apache-2.0](LICENSE). Model weights (`intfloat/multilingual-e5-base`) are MIT.
Location geometry is derived from OpenStreetMap and redistributed under ODbL 1.0.
