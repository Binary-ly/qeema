<!-- SPDX-License-Identifier: Apache-2.0 -->

# Phase 13 — Closing the loop

**Status:** 13.1, 13.2, 13.3, 13.5 and 13.6 complete and verified on a running
stack; **13.4 (FX ingestion) is the only part still open**
**Prerequisite:** Phases 0–12 complete (`PROGRESS.md`)

> **Update, 2026-08-10.** The pipeline and the clock are built. A price posted
> to the public API now reaches the published index in about 75 seconds with no
> command run by anyone, and the eleven submissions stranded before this phase
> were adopted by the first sweep. Two things were found along the way and are
> recorded in `PROGRESS.md`: the anomaly endpoint had never worked in
> production (a slotted dataclass serialised with `__dict__`, invisible because
> no test crossed the HTTP boundary), and every anomaly verdict was being stored
> without a model version. Both fixed.

---

## 1. The problem, stated precisely

Every stage of the ingestion pipeline is built, unit-tested and unreachable. A
price submitted through the public API is written to `submissions` with status
`pending` and stays there permanently, because nothing in the running system
ever calls the code that would advance it.

Verified against the composed stack on 2026-08-10:

```
POST /api/v1/submissions          → 200 {"submission_status":"pending"}
submissions.status                → pending          (unchanged after 10 min)
resolutions   for that submission → 0 rows
price_observations                → 0 rows
redis queue                       → 0 pending, 0 delayed
```

The three actions that would have moved it:

| Action | What it does | Production callers |
|---|---|---|
| `ResolveSubmission` | submission → matched item → `price_observation` | **0** (only `ApplyReviewDecision` and tests) |
| `ScoreSubmissionAnomaly` | observation → anomaly verdict | **0** |
| `ApplyReviewDecision` | human verdict → observation + learned variant | **0** (tests only) |

Three further gaps compound it:

- **No scheduler.** `routes/console.php` contains only Laravel's stock
  `inspire`. There is no `Schedule::` call anywhere in the codebase, so
  `qeema:index` — which drains stale snapshots so a correction reaches the
  published figures — is a command nobody runs. Even with observations
  arriving, the published index would freeze on the day of deployment.
- **No new dates.** `qeema:index` without `--from` only recomputes snapshots
  that already exist. Nothing creates the snapshot for *today*, so a live
  deployment would never publish a new date at all.
- **No FX ingestion.** `FxRateProvider` is an interface with zero
  implementations, though `countries/*.yaml` already declares
  `fx.provider: manual | generic_http | …`. Without a rate for today, every
  `cost_usd` degrades to stale and then, past `max_staleness_days`, to null.

The demo is convincing because `SyntheticDataGenerator` bulk-inserts
submissions, resolutions, observations **and** anomaly scores directly
(`SyntheticDataGenerator.php:707-712`) — it writes the pipeline's outputs, not
its inputs. Nothing between them has ever run in anger.

### The invariant this phase establishes

> **Every submission reaches a terminal state, and every valid observation
> reaches a published snapshot, within a bounded and monitored time — or an
> operator is told why not.**

Three clauses, each load-bearing. *Terminal state* rules out the current
silent-`pending` failure. *Bounded* makes "live" a measurable claim rather than
an aspiration. *Or an operator is told* is what separates a system that is
running from a system that merely started.

---

## 2. The loop, once closed

```
  reporter app ──POST──▶ RecordSubmission ──▶ submissions(pending)
  partner CSV  ──▶ PartnerFileImporter ──────▶ submissions(pending)
                                                    │
                          ┌─────────────────────────┴──────────────────────┐
                          │ fast path: dispatch on write                   │
                          │ safety net: qeema:pipeline:sweep (every minute)│
                          └─────────────────────────┬──────────────────────┘
                                                    ▼
                                        ResolveSubmissionJob
                                     (ML match → item, or review)
                                                    │
                        ┌───────────────────────────┴────────────────┐
                        ▼                                            ▼
              price_observations                          submissions(needs_review)
                        │                                            │
                        ▼                                   ReviewQueue (Filament)
                 ScoreAnomalyJob                          ApplyReviewDecision
              clean │ suspect │ rejected                  approve → observation
                    │         └──────────▶ needs_review    reject  → invalidate
                    ▼                                      both    → reputation
        PriceObservationObserver ──▶ index_snapshots.is_stale = true
                                                    │
                    ┌───────────────────────────────┴───────────────┐
                    │ qeema:index          (drain stale, every min) │
                    │ qeema:index:publish  (roll forward, hourly)   │
                    │ qeema:fx:fetch       (daily, per country)     │
                    └───────────────────────────────┬───────────────┘
                                                    ▼
                                 published: dashboard, API, CSV export
```

Everything in the boxes exists and is tested. This phase builds the arrows.

---

## 3. Design decisions

### D-11 — A fast path *and* a reconciler, not one or the other

Dispatching a job when a submission is written gives the latency the word
"live" implies. It is also the wrong thing to rely on alone: `RecordSubmission`
is not the only writer. `PartnerFileImporter` bulk-inserts via the query
builder (`PartnerFileImporter.php:253`), which fires no model events, and any
future importer will be written by someone who has never read this document.

So the fast path is an optimisation and the **reconciler is the guarantee**:
`qeema:pipeline:sweep` runs every minute and dispatches work for anything
pending beyond a grace age, regardless of how it got there. A submission
stranded by a lost job, a queue flush, a container kill or a code path nobody
anticipated is picked up on the next tick.

This is also what retires the current backlog: the 11 stranded rows need no
special backfill script, they are simply the sweeper's first tick.

*Rejected:* a Submission model observer. It would miss every bulk insert, which
is exactly the write path most likely to produce a backlog worth noticing.

### D-12 — During an ML outage the job waits; it does not flood the review queue

`ResolveSubmission` routes to human review when the matcher returns null, and
that is correct for a direct call: never guess. But as the *automatic* response
to a five-minute container restart it is a bad trade — a thousand submissions
that would each have resolved in 40 ms become a thousand items of human work,
and the queue a real deployment cannot drain is the queue that kills the
project.

The deferral therefore lives in the job, not the action:

```
if (! $ml->isAvailable() && $this->attempts() < $maxAttempts) {
    $this->release($backoff);      // circuit is open; try again later
    return;
}
$resolver->handle($submission);    // last attempt: route to review, honestly
```

`ResolveSubmission`'s semantics are untouched, so its existing tests keep their
meaning, and the smarter behaviour sits at the orchestration layer where the
retry budget is already a concept.

### D-13 — A dedicated `scheduler` container

Laravel's scheduler needs a process. Two candidates: add `schedule:work` to the
worker container under supervisord, or run a fourth service.

A dedicated service wins on the failure mode. If the scheduler dies inside the
worker container, `docker compose ps` still shows the worker healthy — Horizon
is fine — while the index quietly stops updating. That is precisely the class
of invisible failure this whole phase exists to eliminate. A separate service
crashes visibly, restarts independently, and costs nothing extra to build: it
reuses the app image.

It is also guarded from within: a `->everyMinute()` heartbeat writes
`qeema:scheduler:last_tick` to Redis, the container healthcheck asserts that
tick is fresh, and the pipeline health check reports a stopped scheduler as a
first-class condition.

### D-14 — "Today" is a per-country question

The roll-forward step must publish a snapshot for the current date. There is no
such thing as *the* current date on this platform: `countries.timezone` is
`Africa/Tripoli` for one deployment and `America/Caracas` for another, eleven
hours apart. A server-local "today" would publish tomorrow's snapshot early in
one country and yesterday's late in the other.

`qeema:index:publish` therefore computes `CarbonImmutable::now($country->timezone)`
per country. Constraint C3 is not only about literals in code; a hardcoded
notion of *when* is the same mistake in a different dimension.

### D-15 — A publish grace window

The observer marks snapshots stale the instant an observation is created, inside
`ResolveSubmission`'s transaction. Anomaly scoring happens a second or two
later, in the next job. A drain running in that gap would publish a figure
including a not-yet-screened price, then correct it seconds later when the
detector rejects it.

Self-correcting, but briefly wrong in public — and this project's whole claim is
about not being briefly wrong in public. `qeema:index` gains
`--grace=SECONDS` (default 60) and skips snapshots marked stale more recently
than that. This requires a `stale_marked_at` timestamp, which pays for itself
twice: it also gives the operator *backlog age*, the single most useful signal
about whether recomputation is keeping up.

### D-16 — A rate an operator typed outranks a rate a machine fetched

`fx_rates` is already keyed `(country_id, rate_date, source)` with an
`is_manual` flag, so several sources may hold a rate for the same day.
`FxRateResolver::rateOn()` currently resolves ties with
`orderByDesc('fetched_at')`. Harmless today — there is exactly one source — but
the moment a provider exists, tonight's automated fetch silently overwrites the
correction an operator typed this afternoon after speaking to someone at the
market.

Precedence becomes explicit: `is_manual DESC, fetched_at DESC`. A human who
intervened did so for a reason, and the machine does not get to overrule it
without anyone noticing.

### D-17 — Bounded automatic retries end in the review queue, never in silence

A submission whose resolution keeps failing for a reason that is neither an ML
outage nor a low-confidence match — a malformed unit, an encoding a normaliser
chokes on, a genuine bug — must not loop forever, and must not vanish. After
`QEEMA_PIPELINE_MAX_ATTEMPTS` it is routed to `needs_review` with the error
recorded in the resolution notes, and the failure is counted in pipeline health.

Three new columns on `submissions` (`pipeline_attempts`,
`pipeline_attempted_at`, `pipeline_last_error`) carry this. They are pipeline
bookkeeping, not data: every `raw_*` field remains immutable, and corrections
still supersede rather than overwrite.

### D-18 — Public health reports states; the numbers live behind the login

`/api/v1/health` is unauthenticated (C6) and gains a `pipeline` block, but it
reports *states* — `ok`, `degraded`, `stalled` — and ages, not counts. Publishing
"1,412 submissions awaiting review" tells an honest observer very little and
tells someone probing for a manipulation window quite a lot: it is a direct
readout of how thin the screening currently is.

The counts go to a Filament dashboard widget and structured logs, where the
people who can act on them are.

---

## 4. The work

Six sub-phases. Each ends with the full suite green, both coverage gates held,
a commit, and a `PROGRESS.md` entry. No sub-phase begins with the previous one
failing.

### 13.1 — The resolution pipeline

**New**

| File | Purpose |
|---|---|
| `app/Jobs/ResolveSubmissionJob.php` | One submission → resolution. `ShouldQueue`, `ShouldBeUnique` on submission id. Skips unless status is `pending`, so retries and double dispatch are no-ops. Defers while the ML circuit is open (D-12). Dispatches the scoring job on success. |
| `app/Jobs/ScoreSubmissionAnomalyJob.php` | One observation → anomaly verdict. Skips if an `AnomalyScore` already exists. Wraps the existing batch action with a collection of one. |
| `app/Jobs/ResolveIngestionBatchJob.php` | Fans a completed partner import out in chunks onto the bulk queue, so a 50k-row upload cannot starve live reporter traffic. |
| `app/Console/Commands/PipelineSweepCommand.php` | `qeema:pipeline:sweep` — the reconciler (D-11). Dispatches for pending submissions older than the sweep age, and for valid observations with no anomaly score. Bounded by `--limit`. |
| `database/migrations/*_add_pipeline_columns_to_submissions.php` | `pipeline_attempts`, `pipeline_attempted_at`, `pipeline_last_error` (D-17). |
| `config/horizon.php` | Published and tuned: `pipeline-live` and `pipeline-bulk` supervisors, tries, timeouts, memory, `waits` thresholds for alerting. Currently the vendor default is in force by accident. |

**Changed**

- `RecordSubmission::handle()` — dispatch `ResolveSubmissionJob` after commit,
  on `accepted` only. Never on `duplicate`: a replayed offline submission must
  not re-enter the pipeline.
- `PartnerFileImporter::import()` — dispatch `ResolveIngestionBatchJob` on
  completion.
- `config/qeema.php` — a `pipeline` block (queue names, max attempts, sweep age
  and limit).

**Safety.** `price_observations.submission_id` already carries a UNIQUE index,
so a duplicate observation is impossible at the database level even if two
workers race; the job catches `UniqueConstraintViolationException` and treats it
as success, the same pattern `RecordSubmission` already uses for idempotency
keys.

Two details that are easy to get wrong and expensive to debug. The job's
`uniqueFor` must exceed the worst-case deferral window from D-12, or a
submission waiting out an ML outage can have a second job dispatched alongside
it by the sweeper. And the job timeout must stay below the queue's
`retry_after`, or a slow job is handed to a second worker while the first is
still running — the unique index would catch the duplicate, but as an error
rather than as the intended design.

**Tests** (`tests/Feature/Pipeline/`, `tests/Unit/Jobs/`)

- resolves a pending submission into a valid observation
- is a no-op on a submission that is already resolved *(idempotency)*
- is a no-op when replayed after a duplicate submission
- two concurrent runs produce exactly one observation *(unique-index backstop)*
- releases rather than resolving while the ML circuit is open *(D-12)*
- routes to review on the final attempt when the circuit never closes
- records the error and routes to review after `max_attempts` *(D-17)*
- `RecordSubmission` dispatches on accept and not on duplicate *(`Bus::fake`)*
- the sweep dispatches for a stranded pending submission
- the sweep dispatches scoring for a valid unscored observation
- the sweep respects `--limit` and dispatches nothing when idle
- partner import fans out onto the bulk queue

**Acceptance.** A submission posted to the API becomes a `price_observation`
with an anomaly verdict, with the queue worker running and no human involved.

### 13.2 — The clock

**New**

| File | Purpose |
|---|---|
| `app/Console/Commands/PublishIndexCommand.php` | `qeema:index:publish` — for each active country, computes "today" in that country's timezone (D-14) and ensures a snapshot exists for `[today − backfill_days, today]` for every active location. This is what makes new dates appear. |
| `database/migrations/*_add_stale_marked_at_to_index_snapshots.php` | Backs the grace window and backlog-age metric (D-15). |
| `routes/console.php` | The schedule, with `->onOneServer()->withoutOverlapping()` throughout. |
| compose service `scheduler` | `php artisan schedule:work` on the existing app image, heartbeat-based healthcheck (D-13). |

**The schedule**

| Task | Cadence | Why |
|---|---|---|
| heartbeat | every minute | proves the scheduler is alive; feeds the healthcheck |
| `qeema:pipeline:sweep` | every minute | the reconciler |
| `qeema:index --grace=60` | every minute | corrections reach published figures within ~2 min |
| `qeema:index:publish` | hourly | today's snapshot exists and absorbs late arrivals |
| `qeema:fx:fetch` | daily, plus hourly retry while today is missing | 13.4 |
| `qeema:pipeline:health` | every 5 min | 13.5 |
| `horizon:snapshot` | every 5 min | queue metrics |
| `queue:prune-failed --hours=168` | daily | bounded growth |

**Changed**

- `IndexStaleness::markRange()` — stamp `stale_marked_at` on first marking only,
  so backlog age reflects the *oldest* reason a snapshot is stale.
- `RecomputeIndexCommand` — `--grace` option; report oldest backlog age.

**Tests**

- publish creates today's snapshot per country timezone, with a country whose
  offset puts it on a different calendar day from the server *(D-14)*
- publish is idempotent across repeated runs
- publish backfills exactly `backfill_days`
- drain skips a snapshot inside the grace window and takes it after
- `stale_marked_at` survives a second marking *(oldest wins)*
- the schedule registers each expected command at the expected cadence *— the
  regression guard that fails if a task is ever dropped*

**Acceptance.** With the stack running and no manual commands, the observation
from 13.1 appears in the published API response for its location and date
within two minutes; a new calendar day produces a new snapshot without
intervention.

### 13.3 — The review queue

1,127 submissions sit in `needs_review` in the demo database with no screen to
action them, and every ML outage adds more. The actions are built and tested;
this is the missing surface.

**New**

| File | Purpose |
|---|---|
| `app/Filament/Resources/ReviewQueue/*` | A resource over `Submission`, scoped to `awaitingReview()`. |

**Requirements**

- **Context in one view**, because a reviewer without context is a coin flip:
  the raw text as submitted, the matcher's suggestion with its confidence *and
  the runners-up* (`resolutions.candidates` already stores them), the anomaly
  verdict with its reasons, recent local prices for the suggested item, the
  reporter's reputation and history, and the photo where present.
- **Actions**: approve with an item picker defaulting to the suggestion; reject
  with a mandatory reason. Both call `ApplyReviewDecision`, which already
  learns the variant, updates reputation and creates the observation.
- **Bulk approve** for the dominant case — the matcher was right and merely
  under threshold. Without it the queue is not drainable by one person.
- **Ordering**: oldest first by default, with a highest-impact option (basket
  weight × recency), because a reviewer with an hour should spend it on the
  items that move the published figure.
- **Filters**: country, location, reason class (matcher unavailable / low
  confidence / anomaly suspect / anomaly rejected), date range.
- Reviewer identity recorded on every decision (`reviewed_by_user_id`).

**Note.** An approval creates the observation, which fires the observer, which
marks snapshots stale, which the scheduled drain republishes — the human branch
closes through exactly the same path as the automatic one. A human verdict is
not re-scored by the anomaly detector, consistent with the existing rule that
only a human verdict moves reputation.

**Tests** (`tests/Feature/Admin/ReviewQueueTest.php`)

- the queue lists only `needs_review` and only for permitted countries
- approving creates the observation, learns the variant and marks the snapshot
  stale
- approving twice does not create a second observation
- rejecting invalidates any existing observation and records the reason
- bulk approve applies to every selected row and to none of the rest
- the reviewer id is recorded
- the page is unreachable when not authenticated

**Acceptance.** A reviewer can take a submission from the queue to a published
figure without touching a terminal.

### 13.4 — FX ingestion

**New**

| File | Purpose |
|---|---|
| `app/Services/Fx/Providers/ManualFxProvider.php` | Key `manual`. Returns null by design — it makes "there is no automatic source here" an explicit, logged state rather than a missing binding. |
| `app/Services/Fx/Providers/GenericHttpFxProvider.php` | Key `generic_http`. Operator-supplied URL and field paths from `countries/*.yaml`. |
| `app/Services/Fx/FxProviderRegistry.php` | `fx_config.provider` → implementation; unknown keys fall back to manual, loudly. |
| `app/Console/Commands/FetchFxRatesCommand.php` | `qeema:fx:fetch`. Per country, per country-timezone date, upsert on `(country_id, rate_date, source)`. |

**Constraint C1.** No vendor endpoint, no key, no account: the generic provider
ships **disabled**, and a deployment that has a source points at it in its own
country file. Libya's parallel rate has no free machine-readable feed I would
stake a published figure on, so `manual` remains the shipped default and the
operator workflow is the admin panel — which is why 13.5's staleness alerting is
part of this phase and not a nicety.

**Hardening.** `SECURITY.md` already names SSRF through operator-supplied FX
configuration as in scope. The generic provider enforces: https/http only, DNS
resolution checked against private and link-local ranges before connecting, no
redirects to a different host, a hard timeout and a response size cap.

**Changed**

- `FxRateResolver::rateOn()` and its fallback — deterministic precedence
  `is_manual DESC, fetched_at DESC` (D-16).

**Tests**

- registry resolves each configured key and falls back for an unknown one
- generic provider parses a configured payload and upserts
- generic provider refuses a private-range host, a non-http scheme, an
  oversized body, a cross-host redirect
- a fetched rate never displaces a manual rate for the same day *(D-16)*
- a country on `manual` is a clean no-op, not an error
- rates are stamped with the country-local date, not the server's

### 13.5 — Observability, and the runbook

A loop nobody is watching is a loop that stops on a Tuesday and is noticed in
March.

**New**

| File | Purpose |
|---|---|
| `app/Services/Pipeline/PipelineHealth.php` | Computes the invariant checks in one place. |
| `app/Console/Commands/PipelineHealthCommand.php` | `qeema:pipeline:health` — evaluates, logs structured warnings, caches for the endpoint. |
| `app/Filament/Widgets/PipelineHealthWidget.php` | The numbers, behind the login (D-18). |
| `docs/operations.md` | The runbook. |

**Checks**

| Signal | Healthy | Meaning when it trips |
|---|---|---|
| scheduler heartbeat age | < 3 min | the clock stopped: nothing is publishing |
| oldest `pending` submission | < 10 min | the fast path and the sweeper are both failing |
| oldest stale snapshot | < 15 min | recomputation is not keeping up with ingestion |
| newest snapshot date per country | today (country tz) | roll-forward is not running |
| FX age per country | ≤ `max_staleness_days` | USD figures are about to go null |
| review backlog & trend | not growing over 24 h | more arriving than a human can clear |
| ML circuit | closed | everything is routing to human review |
| failed jobs (24 h) | 0 | a code path is broken, not an outage |

Public `/api/v1/health` gains a `pipeline` block of states and ages only; the
counts stay in the widget and the logs (D-18). The OpenAPI document is
regenerated, since CI fails on drift.

**Tests**: each check flips at its threshold; the endpoint exposes no counts;
the widget requires authentication.

### 13.6 — Proof

**`e2e/tests/loop.spec.ts`** — the test that would have caught all of this:

1. read the published snapshot for a location and note the observation count
   for one basket item
2. POST a submission for that item, at that location, using a catalogue code so
   matching is deterministic
3. poll the public API until the observation count increases, with a timeout
   and a clear failure message
4. assert the item is *not* marked imputed, that coverage moved in the right
   direction, and that the raw price is nowhere in the response except through
   the aggregate

**`tests/Feature/Pipeline/ClosedLoopTest.php`** — the same journey in the PHP
suite against a fake ML client, from `POST /api/v1/submissions` through
`Queue::fake()`-less synchronous execution to `qeema:index`, asserting the
published payload changed.

**Docs**: `docs/deployment.md` gains every new `QEEMA_*` variable — enforced in
both directions by the existing `DeploymentDocsTest`, which fails if the guide
names a variable nothing reads *or* omits one something does — plus the
`scheduler` service, and a "what to check when the index stops moving" section.
`PROGRESS.md` and `PLAN.md` record D-11…D-18.

---

## 5. New configuration

Every one of these must appear in `docs/deployment.md` or the suite fails.

| Variable | Default | Purpose |
|---|---|---|
| `QEEMA_PIPELINE_QUEUE_LIVE` | `pipeline-live` | Queue for reporter submissions. |
| `QEEMA_PIPELINE_QUEUE_BULK` | `pipeline-bulk` | Queue for imports and sweeps. |
| `QEEMA_PIPELINE_MAX_ATTEMPTS` | `5` | Attempts before routing to review (D-17). |
| `QEEMA_PIPELINE_SWEEP_AGE` | `120` | Seconds before the reconciler adopts a pending row. |
| `QEEMA_PIPELINE_SWEEP_LIMIT` | `500` | Dispatches per sweep tick. |
| `QEEMA_INDEX_DRAIN_LIMIT` | `500` | Snapshots recomputed per tick. |
| `QEEMA_INDEX_PUBLISH_GRACE` | `60` | Grace window in seconds (D-15). |
| `QEEMA_INDEX_BACKFILL_DAYS` | `3` | Days the roll-forward re-publishes for late arrivals. |
| `QEEMA_FX_FETCH_ENABLED` | `true` | Master switch for automated FX. |
| `QEEMA_FX_HTTP_TIMEOUT` | `10` | Seconds, generic provider. |
| `QEEMA_PIPELINE_ALERT_MINUTES` | `15` | Backlog age that counts as degraded. |

---

## 6. Failure modes considered

| Failure | Behaviour | Mechanism |
|---|---|---|
| ML service down 5 min | jobs defer, nothing enters review, catches up on recovery | D-12 |
| ML service down 2 h | jobs exhaust attempts, route to review with a reason, health reports the breaker open | D-12, D-17 |
| Worker container killed mid-job | job returns to the queue; if lost, the sweeper re-adopts it | D-11 |
| Two workers race one submission | one observation; the loser catches the unique violation | existing UNIQUE index |
| Offline phone replays a day of submissions | duplicates short-circuit before dispatch | existing idempotency key |
| 50k-row partner import | fans onto the bulk queue; live submissions unaffected | separate queues |
| Import marks 1,400 snapshots stale | drained at 500/min, oldest first; backlog age observable | `--limit`, `stale_marked_at` |
| Scheduler dies | container unhealthy within 3 min; health reports `stalled` | D-13 |
| Malformed submission loops | bounded, then review with the error recorded | D-17 |
| Automated FX overwrites an operator's rate | it does not | D-16 |
| FX source unreachable for a week | rate goes stale then USD goes null, and the operator was warned daily | existing resolver + 13.5 |
| Reviewer approves the same row twice | one observation | `ApplyReviewDecision` guard + UNIQUE index |
| Anomaly detector rejects after publication | observation invalidated, snapshot re-marked, republished | existing observer |

---

## 7. Constraint compliance

| | Effect of this phase |
|---|---|
| **C1** no proprietary or paid services | Nothing added contacts a third party. The generic FX provider ships disabled and carries no vendor endpoint or key. |
| **C2** one command | `docker compose up` gains one service from an image it already builds. The demo path is unchanged; the C2 CI job must stay green. |
| **C3** country-agnostic | No new literals. Actively *improves* compliance: "today" becomes per-country (D-14), and FX providers are selected from country configuration. |
| **C4** open source | Apache headers on every new file; new dependencies: none. |
| **C5** ≥80% coverage, tests not deferred | Every sub-phase ships its tests. Both gates must hold at each commit. |
| **C6** public data | The read API is unchanged except an additive health block that exposes states, not counts (D-18). |

---

## 8. Explicitly not in this phase

Closing the loop does not make the platform trustworthy at scale. These remain
open and should not be implied as done:

- **Reporter identity is a client-supplied UUID.** Rotate it, get a fresh
  reputation. The weighted-median defence is bypassable by anyone reading the
  public docs. Needs its own phase — attestation, or invite-scoped reporters for
  a pilot cohort.
- **Every model is validated on synthetic data.** Closing the loop is the
  precondition for fixing this: the review queue is where real labelled
  decisions come from. Until a few thousand exist, the published matching and
  anomaly figures describe a simulation.
- Semantic matching remains unproven; nowcast intervals under-cover
  (74.6% against 80% nominal); chain-linking across basket versions; Alpine's
  CSP build; EXIF stripping on ingest.

---

## 9. Effort

Sequential, tests included, one engineer:

| Sub-phase | Estimate |
|---|---|
| 13.1 resolution pipeline | 1.5–2 d |
| 13.2 the clock | 1–1.5 d |
| 13.3 review queue | 2–3 d |
| 13.4 FX ingestion | 1.5–2 d |
| 13.5 observability + runbook | 1–1.5 d |
| 13.6 proof + docs | 1 d |
| **Total** | **8–11 days** |

13.1 and 13.2 together are the difference between a demo and a pilot; if only
one thing is built, build those.

---

## 10. Acceptance — how to prove it, in public

From a clean machine, with nothing but Docker:

```bash
make nuke && make demo

# 1. note the current state of one location
curl -s localhost:8080/api/v1/countries/LY/index/current | jq '.data[0] | {loc: .location.slug, date, coverage: .quality.coverage}'

# 2. submit a price as a reporter would
curl -s -X POST localhost:8080/api/v1/submissions \
  -H 'Content-Type: application/json' \
  -d '{"reporter_ref":"<uuid>","country":"LY","location_slug":"<slug>",
       "canonical_item_code":"rice_1kg","price":42.5,
       "client_idempotency_key":"<uuid>"}'

# 3. within ~2 minutes, with no further commands, it is published
curl -s localhost:8080/api/v1/locations/<slug>/index/<today> \
  | jq '.data.items[] | select(.item.code=="rice_1kg") | {observation_count, is_imputed}'
```

`observation_count` increases and `is_imputed` is `false`. The same journey runs
unattended as `e2e/tests/loop.spec.ts`.

That sequence is also the honest answer to "is this a prototype?" — it is the
one demonstration that distinguishes a system that publishes data from a system
that displays it.
