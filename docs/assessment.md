<!-- SPDX-License-Identifier: Apache-2.0 -->

# What Qeema does, and what it does not

An honest account of the platform's state, written for somebody deciding
whether to fund or deploy it.

Every claim below says what it was measured against, and how to check it. Where
a number comes from a simulation rather than a market, it says so in the same
sentence — because the difference between those two is most of what matters
here, and it is the difference a reader is least able to see for themselves.

**Last verified:** 13 August 2026.

The gate figures below are a snapshot taken on that date, not a live readout —
CI enforces that the suites pass and the coverage floors hold, but nothing
checks that the numbers written here are still the current ones. Regenerate
them with `make verify`. Treat any figure in this document as "true when
written and worth re-running", which is the honest status of every number in
any document of this kind.

## Contents

- [What it is](#what-it-is)
- [What is proven](#what-is-proven)
- [What is measured only against a simulation](#what-is-measured-only-against-a-simulation)
- [What is not built](#what-is-not-built)
- [The constraints, and evidence for each](#the-constraints-and-evidence-for-each)
- [How to check all of this in half an hour](#how-to-check-all-of-this-in-half-an-hour)

---

## What it is

A platform that publishes a **child-weighted affordability index** for crisis
economies: what a basket of things a child needs — infant formula, flour,
medicine, school supplies, cooking fuel, drinking water — costs in each town,
every day, in local currency and in dollars at the rate people can actually
transact at.

Prices arrive from people with phones, from partner spreadsheets, and from
openly-licensed datasets. They are matched to a catalogue, screened for errors
and manipulation, aggregated into a weighted basket cost, and published through
a public API and dashboard that need no account and no key.

It ships with two countries configured — one Arabic, right-to-left, three
decimal places in its currency; one Spanish, left-to-right, two — and nothing
about either is in the code. Adding a third is a YAML file.

## What is proven

These hold on a real deployment, and CI re-checks every one of them on each
push.

### The loop closes without anyone touching it

A price posted to the public API reaches the published index in about
**75 seconds** — resolved to a catalogue item, screened for anomalies, folded
into the basket, republished — with no command run by any human.

This is the single claim the platform stands on, and until 10 August it was
false: every stage was built and unit-tested, nothing joined them, and a
submitted price sat at `pending` for ever while every test passed. It is now
asserted end to end against the composed stack in CI
(`e2e/tests/loop.spec.ts`), so a regression fails a build rather than a pilot.

### It runs on one command, on a clean machine

`docker compose up` builds six services, migrates, seeds two countries with six
months of synthetic history, computes the index over it, and serves a working
dashboard and API. No hosted service, no account, no API key, and no request
leaves the network at runtime.

CI does this from scratch on every push and then runs 27 browser tests against
the result.

### The reporter app works with no connection

A price entered offline is held in an IndexedDB outbox and sent when signal
returns, exactly once — the idempotency key is enforced by a database
constraint, not by application logic, and a replay is answered `200 duplicate`
rather than an error that would leave it retrying for ever. Verified in a real
browser, offline, on a phone profile.

### Estimates are never presented as measurements

Every imputed value carries `is_imputed`, the method that produced it, and an
interval. Coverage and imputed share travel with every published figure, and a
basket that cannot be compared with another says so. `cost_usd` is **null**
rather than converted when no exchange rate within the operator's staleness
horizon exists.

### Photographs do not carry the photographer

Location metadata is stripped from reporter photographs before the file reaches
disk — EXIF, XMP, IPTC and comments from JPEG, the equivalent chunks from PNG —
losslessly, so a reviewer can still read a price tag. A photograph whose
metadata cannot be removed is not stored, and the submission is still accepted.

### The platform reports when it has stopped working

Every failure mode here looks like silence: the API answers, the dashboard
renders, containers report healthy, and the figures stop moving. Nine checks —
the scheduler's own heartbeat, resolution, recomputation, publication, exchange
rates, the review queue, matching, imputation source, failed jobs — are
evaluated every five minutes, published as states and ages on the public health
endpoint, and shown with counts behind the admin login.

### Quality gates

| Gate | Result |
|---|---|
| PHP suite (Pest) | 609 passed, 1 skipped, 2,233 assertions, **93.6%** coverage |
| Python suite (pytest) | 224 passed, **85.4%** coverage |
| Browser suite (Playwright) | 27 passed against the composed stack |
| Static analysis (PHPStan level 6) | 0 errors |
| Formatting and typing (Pint, ruff, mypy) | clean |
| Country-agnostic check | pass |

## What is measured only against a simulation

**This section is the one to read carefully.** The machine-learning components
have never seen a real market. Every figure below was measured against a
six-month synthetic history produced by a generator whose price process is
defined in this repository — inflation, exchange-rate pass-through with an
import-intensity lag, a regional premium, decaying supply shocks, and a
deliberately planted cluster of manipulating reporters.

A model evaluated on data generated by known rules will do better than one
facing a real market. **Read these as upper bounds.**

| Component | Figure | Measured against |
|---|---|---|
| Product matching | 98.4% top-1, 99.3% precision on auto-resolved | Synthetic reporter text with dialect, dropped hamza, Arabic-Indic digits, typos |
| Price nowcasting | 3.5% MAPE against a 9.0% baseline | Synthetic backtest, temporal split |
| Nowcast interval | 85.6% empirical coverage against 80% claimed | The same backtest |
| Anomaly detection, honest mistakes | 78.0% recall | Labelled synthetic errors |
| Anomaly detection, **coordinated manipulation** | **5.3% recall (4 of 75)** | Labelled synthetic manipulation |
| Reporter-level manipulation | recall 6/8, precision 6/9 | The planted cluster in the demo database |

Two of those deserve comment rather than a row in a table.

**Per-observation screening is nearly blind to coordination — 5.3%.** That is
not a defect being hidden; it is the nature of the problem. A coordinated group
reports prices that are each individually plausible, so nothing examining one
submission can see them. It is exactly why a second, reporter-level detector
exists, which catches 6 of the 8 planted manipulators. Anyone reading a single
"anomaly detection" number is reading the wrong one.

**That detector's precision is 6 of 9.** One flag in three is a person doing
nothing wrong. This is why nothing in the platform blocks a reporter
automatically: an automatic rule on this signal would have silenced three honest
reporters to catch six manipulators. It records a score and a reason in words,
and a human decides.

Converting any of this from "measured on a simulation" to "measured" requires
real submissions flowing through the review queue for some weeks. It is not a
coding task.

## What is not built

Stated plainly, because a reviewer finding these themselves is worse than
reading them here.

**Reporter identity is a client-supplied UUID.** There is no signup, by design —
requiring one would suppress the participation the platform runs on. A reporter
who rotates their identifier still gets a fresh reputation.

Two things narrow this without adding friction, and neither closes it. The
reporter-level manipulation detector works on prices rather than identities, so
a cluster out of step with its neighbourhood stays out of step whatever
identifier it wears. And the estimator now weights an observation by the
*lower bound* of the reporter's reputation posterior rather than its mean, so a
brand-new identity is worth 0.28 against a floor of 0.25 — where under the mean
it was worth 0.5, and discarding a suppressed identity doubled its weight.

What remains is that a patient attacker with many identities and plausible
prices is still not individually detectable. Closing that needs an identity
decision — invite-scoped cohorts, phone verification, device attestation — each
of which trades away some of the openness the design is protecting.

**Real-data validation.** As above.

**Chain-linking across basket versions.** Revising a country's basket breaks
comparability of the series across the revision. Fine for a pilot; not fine for
a multi-year index.

**Automated exchange-rate sourcing for most currencies.** The platform reads any
JSON endpoint an operator configures, and ships configured with none. The
parallel-market rates that matter in these economies are behind API keys, and
depending on one would breach the no-proprietary-services constraint. Manual
entry is the default, and the health check warns before a stale rate withdraws
dollar figures.

**Fitted models are lost if the ML container's volume is lost.** They are
persisted and restored across restarts, and regenerated by the next training run
otherwise — so this costs one scheduled run, not any history.

**One flaky browser test.** An offline-reporter test fails intermittently under
CI load and is undiagnosed. Two hypotheses were tested and disproved. It is
recorded rather than papered over.

**The reporter app needs `unsafe-eval` on three routes**, because Alpine
compiles expressions with `new Function()`. The public dashboard, API, docs and
export keep a strict content-security policy. Alpine ships a CSP-safe build that
would remove the exception.

## The constraints, and evidence for each

The project was built to six hard constraints. Each is checked mechanically
rather than asserted.

| | Constraint | Evidence |
|---|---|---|
| **C1** | No proprietary or paid service in the runtime path; all models local from open weights; all dependencies OSI-licensed | Dependency licence inventory generated in CI; embedding weights baked into the image at build time and loaded offline; no exchange-rate or scraper source configured by default; a browser test asserts no request leaves localhost |
| **C2** | Self-hostable in one command | CI builds and boots the whole stack from scratch on every push, then runs the browser suite against it |
| **C3** | Country-agnostic | A CI job greps application source for country literals and fails the build on one — it has caught three, including a currency code that reached the published API contract and a timezone in a comment |
| **C4** | Apache-2.0 end to end | LICENSE, NOTICE, CONTRIBUTING, CODE_OF_CONDUCT; SPDX header on every file |
| **C5** | ≥80% unit coverage, enforced in CI from the first phase | 93.7% and 85.4%, both gated |
| **C6** | The public data is the product | Every read route unauthenticated, OpenAPI documented and drift-checked in CI, bulk CSV export carrying its licence; a browser test asserts no endpoint requires credentials |

## How to check all of this in half an hour

Nothing here needs to be taken on trust.

```bash
git clone <repository> && cd qeema
make demo                       # one command, ~15 minutes on a first build
```

Then:

```bash
# 1. The data is public. No key, no account.
curl -s localhost:8080/api/v1/countries/LY/index/current | jq '.data[0]'

# 2. Estimates are labelled as estimates.
curl -s "localhost:8080/api/v1/locations/tripoli/index/$(date -u +%F)" \
  | jq '.data.items[] | {code: .item.code, is_imputed, imputation_method}'

# 3. The platform says whether it is still publishing.
curl -s localhost:8080/api/v1/health | jq .pipeline

# 4. The loop closes. Post a price; watch it appear.
#    (docs/operations.md has the full command with a real location and item.)

# 5. The claims in this document are the ones CI checks.
make verify                     # lint, both suites, coverage gates, C3 check
cd e2e && npx playwright test   # 27 browser tests against the running stack
```

The development record is in [PROGRESS.md](../PROGRESS.md), kept accurate rather
than aspirational — including the things that were wrong, the fixes that were
measured and backed out, and five components that were complete, tested, and
connected to nothing until somebody went looking.
