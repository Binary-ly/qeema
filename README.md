<div align="center">

# Qeema · قيمة

**A live, child-weighted affordability index for crisis economies.**

[![CI](https://github.com/Binary-ly/qeema/actions/workflows/ci.yml/badge.svg)](https://github.com/Binary-ly/qeema/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Coverage gate](https://img.shields.io/badge/coverage-%E2%89%A580%25-brightgreen.svg)](#testing)
[![Data license](https://img.shields.io/badge/data-CC--BY--4.0-blue.svg)](LICENSE-DATA)
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
git clone https://github.com/Binary-ly/qeema.git
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

The ML service is the only one that publishes a port nothing needs: the app
reaches it over the Docker network at `http://ml:8000`, and the host mapping
exists purely so you can poke at it. It also collides with `php artisan serve`,
whose default is `127.0.0.1:8000`, so if you run another Laravel app locally put
`ML_PORT=8001` in a `.env` beside `docker-compose.yml` and nothing else changes.

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

## Use the data

No key, no account, no rate-limit tier. Constraint C6: the data being open **is**
the product.

```bash
# Latest figure for every location in a country
curl https://your-host/api/v1/countries/LY/index/current

# The whole published history as CSV (-OJ keeps the filename the server sends)
curl -OJ "https://your-host/api/v1/countries/LY/export.csv?from=2026-01-01"

# The same file, tagged for the humanitarian data ecosystem
curl -OJ "https://your-host/api/v1/countries/LY/export.csv?hxl=1"
```

`?hxl=1` adds a HXL (Humanitarian Exchange Language) hashtag row beneath the
header — `#date`, `#loc+name`, `#value+cost+usd` — so a consumer can map the
columns mechanically instead of by hand. It is opt-in because to a parser that
has not been told about HXL the tag row is an ordinary data row.

**Read this before relying on it.** OCHA retired its hosted HXL services on
31 January 2026 — `hxlstandard.org`, the HXL Proxy and HDX Quick Charts are all
gone — and HDX now says it "will no longer be asking data contributors to add
HXL tags". The *standard* was not retired ("HXL is an open standard and will
remain available for organizations that wish to continue using it within their
internal workflows"), `libhxl` is still published, and current humanitarian
datasets still ship tag rows. So this is useful interoperability for
organisations that use HXL internally — not, any longer, the ascendant
convention of the sector.

**Every qualifier travels with every number.** Coverage, imputed share, the
confidence interval, whether the figure is `comparable` across a basket
revision, and whether the exchange rate is stale are fields and columns, not
footnotes. A figure that was estimated always says so.

Full reference: OpenAPI 3.0 at `/docs`, generated from the source and checked by
CI for drift.

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
| [docs/pilot.md](docs/pilot.md) | Running a first pilot: the first weeks with real reporters, and how to turn them into evidence |
| [docs/scale-testing.md](docs/scale-testing.md) | Testing at millions of rows, and against reporter text the matcher was not tuned on |
| [PLAN.md](PLAN.md) | Technical plan, schema, formulas, decision log |
| [PROGRESS.md](PROGRESS.md) | Current build state, honestly reported |
| [docs/adr/](docs/adr/) | Architecture decision records |
| [docs/model-cards/](docs/model-cards/) | Training data, metrics and limitations for each ML component |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development setup and the rules that are not negotiable |
| [SECURITY.md](SECURITY.md) | Threat model and private disclosure |
| [docs/do-no-harm.md](docs/do-no-harm.md) | **How this platform could harm people while working exactly as designed, and what is done about it** |
| [docs/privacy.md](docs/privacy.md) | What personal data exists, why, how to erase it, and what is not retained |
| [docs/dpg-standard.md](docs/dpg-standard.md) | Self-assessment against the nine Digital Public Good indicators |
| [docs/data-sources.md](docs/data-sources.md) | **Verified inventory of real price data — what exists, what is licensed for reuse, and which sources are mislabelled** |

---

## Status

Under active development. See [PROGRESS.md](PROGRESS.md) for exactly what works
today and what does not — it is kept accurate rather than aspirational.

## Licence

**Software:** [Apache-2.0](LICENSE).
**Data:** [CC BY 4.0](LICENSE-DATA) — every price, index snapshot and export.
The bulk CSV sends `X-Qeema-License` so the licence travels with a downloaded
file.

Two licences because they are two different things used by different people: a
developer forking the platform needs patent and contribution terms, an analyst
putting a chart in a report needs something open-data catalogues recognise.

Model weights (`intfloat/multilingual-e5-base`) are MIT. Location geometry is
derived from OpenStreetMap and redistributed under ODbL 1.0.
