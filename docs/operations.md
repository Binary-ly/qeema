<!-- SPDX-License-Identifier: Apache-2.0 -->

# Running Qeema

Deployment is in [deployment.md](deployment.md). This is the other half: what
the platform does on its own, how to tell whether it is still doing it, and what
to do when it is not.

## Contents

- [The one thing to understand](#the-one-thing-to-understand)
- [What runs on its own](#what-runs-on-its-own)
- [Checking that it is working](#checking-that-it-is-working)
- [The signals, and what to do about each](#the-signals-and-what-to-do-about-each)
- [Daily and weekly](#daily-and-weekly)
- [Things that are supposed to happen](#things-that-are-supposed-to-happen)

---

## The one thing to understand

**Every way this platform fails looks like silence.**

There is no error page when the index stops updating. The API answers, the
dashboard renders, the containers report healthy, and the published figures
quietly stop moving. A reporter's price is accepted with a `201` whether or not
anything will ever process it. An exchange rate going stale does not break a
page; it makes every dollar figure disappear, correctly and without comment.

Everything below exists because of that. The platform is built to keep serving
degraded rather than fail loudly — which is right for the people reading it, and
means the operator has to go looking.

## What runs on its own

The `scheduler` container runs these. If it is not running, none of them are.

| Task | Cadence | What stops without it |
|---|---|---|
| `qeema:scheduler:heartbeat` | every minute | The healthcheck that tells you the rest of this list has stopped |
| `qeema:pipeline:sweep` | every minute | Submissions written by anything other than the API are never processed |
| `qeema:index` | every minute | Corrections never reach published figures |
| `qeema:index:publish` | hourly | No new calendar day is ever published |
| `qeema:fx:fetch` | hourly | Dollar figures go stale, then null, for countries with a configured source |
| `qeema:nowcast:train` | every 6 hours | Estimates revert to a crude fallback heuristic |
| `qeema:reporters:bias` | daily, 03:20 | Nobody looks for coordinated price manipulation |
| `qeema:pipeline:health` | every 5 minutes | Nothing tells you any of the above stopped |
| `horizon:snapshot` | every 5 minutes | Queue metrics in the Horizon dashboard |
| `queue:prune-failed` | daily | Failed-job history grows without bound |

The `worker` container runs the jobs those tasks dispatch. Both are separate
services on purpose: a scheduler that dies inside a healthy-looking worker is
the exact failure this design refuses to allow.

## Checking that it is working

In order of how much they tell you per second spent:

```bash
# 1. Is anything wrong at all? Public, no login.
curl -s localhost:8080/api/v1/health | jq .pipeline.status

# 2. Which part? Still public — states and ages, never counts.
curl -s localhost:8080/api/v1/health | jq .pipeline

# 3. The numbers, with the same checks and the counts attached.
docker compose exec app php artisan qeema:pipeline:health

# 4. The same thing, on the admin dashboard, refreshed every minute.
open http://localhost:8080/admin
```

`qeema:pipeline:health --strict` exits non-zero when anything is not `ok`. That
is the form to point an external monitor at; the scheduled run deliberately
exits zero even when degraded, so that a genuine stop is not buried under
routine lateness.

To prove the whole loop end to end — the check worth running after any upgrade:

```bash
# Submit a price, then watch it appear in the published index.
curl -s -X POST localhost:8080/api/v1/submissions -H 'Content-Type: application/json' \
  -d '{"reporter_ref":"'$(uuidgen)'","country":"LY","location_slug":"<slug>",
       "canonical_item_code":"<item>","price":9.75,"client_idempotency_key":"'$(uuidgen)'"}'

# Within about 75 seconds, observation_count for that item goes up by one.
curl -s "localhost:8080/api/v1/locations/<slug>/index/$(date -u +%F)" \
  | jq '.data.items[] | select(.item.code=="<item>") | {observation_count, is_imputed}'
```

## The signals, and what to do about each

### `scheduler` — stalled

**Everything else on this page is downstream of this one.** A stopped clock
explains every other symptom at once; start here and do not debug a consequence.

```bash
docker compose ps scheduler
docker compose logs --tail=100 scheduler
docker compose restart scheduler
```

If it restarts and immediately stops again, the usual cause is a configuration
error that only surfaces at boot — `php artisan config:cache` runs in the
entrypoint and will report it.

### `resolution` — degraded

Submissions are arriving and not being turned into observations. Both the
dispatch-on-write path *and* the every-minute sweeper would have to be failing,
so this is rarely subtle.

```bash
docker compose ps worker                       # is anything consuming the queue?
docker compose exec app php artisan horizon:status
docker compose exec app php artisan qeema:pipeline:sweep --now
```

If the sweeper dispatches work and nothing consumes it, the worker is the
problem. If jobs are being consumed and submissions stay pending, look at
`failed_jobs` below.

### `recomputation` — degraded

Observations exist that the published figures do not yet reflect. Usually a
large import that marked thousands of snapshots stale; the drain works through
500 a minute by default and will catch up on its own.

```bash
docker compose exec app php artisan qeema:index --limit=2000
```

If the backlog is not shrinking between runs, something is marking snapshots
stale faster than they can be recomputed — check whether an importer is
re-uploading the same file repeatedly.

### `publication` — degraded

A country has no published figure for its own current date. Either the hourly
roll-forward is not running (see `scheduler`), or the country has locations and
a basket but has never been computed at all:

```bash
docker compose exec app php artisan qeema:index:publish --country=<ISO2>
```

### `exchange_rates` — degraded

**Act on this one.** Past the staleness horizon the platform stops converting
and publishes `cost_usd` as null — honest, and the figure most external
consumers are reading. Enter today's rate under **Admin → FX rates**, or
configure an automatic source in the country's YAML.

A rate typed by an operator is not overwritten by an automatic fetch.

### `review_queue` — degraded

Submissions have been waiting for a human longer than they should. Size is not
the signal; a large queue being worked through is healthy. Age means the queue
has an owner in theory only — and every submission in it is a price the platform
has decided not to publish.

Go to **Admin → Ingestion → Review queue**, sort by basket weight, and use the
bulk *Approve the suggested match* action for the common case. See
[deployment.md](deployment.md#reviewing-what-the-pipeline-could-not-decide).

### `matching` — degraded

The ML service is unreachable and the circuit breaker is open. Submissions are
not being lost: they defer, retry, and only reach the review queue if the outage
outlasts their retry budget.

```bash
docker compose ps ml
docker compose logs --tail=100 ml
docker compose restart ml
```

The breaker closes on its own after the cooldown. To close it immediately after
fixing the cause, restart the `app` container.

### `imputation` — degraded

Estimates for unobserved basket items are coming from the fallback heuristic
(±30%) rather than the trained model. The figures still publish, still carry an
interval and are still labelled imputed — they are simply much cruder than the
model card describes, and nothing else in the system would tell you.

Fitted models are persisted to the `ml-models` volume and read back at startup,
so an ordinary restart no longer costs you the model. This signal therefore
means one of three things: the model has never been trained on this deployment,
the persisted model was **refused** at startup because it was fitted on a
different feature set or different quantiles (look for a `Refusing the model`
warning in `docker compose logs ml`), or training has been failing.

```bash
docker compose exec app php artisan qeema:nowcast:train
docker compose exec app php artisan qeema:index --grace=0   # republish with model estimates
```

To confirm which, ask the service directly — `model_trained` is the answer:

```bash
docker compose exec ml sh -lc 'ls /models/*/manifest.json'   # what is persisted
```

If training reports "declined", there is not yet enough history — the model
needs a few hundred usable rows and refuses rather than fitting noise. That is
the correct state for a new deployment, and the fallback is doing its job in the
meantime.

The `ml-models` volume does not need backing up. Everything in it is
regenerated by the next training run from data that *is* backed up; losing it
costs one scheduled run, not any history.

### A reporter has been flagged for possible manipulation

Not a health signal — it appears in the log and against the reporter in
**Admin → Reporters**, where the reason is shown next to the block toggle.

The detector looks for reporters whose prices sit consistently away from what
everybody else in the same place reports for the same item, measured across
weeks. A coordinated group reports prices that are each individually plausible,
so this is the only view in which they are visible at all.

**It flags; it does not block.** Nothing in the platform suspends a reporter
automatically, and that is deliberate. Measured against the demo data's known
manipulators the detector catches 6 of 8, and 3 of the 9 it flags are doing
nothing wrong — so roughly one flag in three is a person who would have been
silenced by an automatic rule for reporting honestly from an unusual market.

What to do with a flag:

1. Open the reporter in the admin panel and read the reason. It states how far
   below the local price their lowest decile sits, and over how many
   observations.
2. Look at their submissions. A reporter working one genuinely cheap market
   looks different from one whose prices are low everywhere they go.
3. Decide. Blocking is a toggle on the reporter; leaving them alone clears the
   flag until the pattern changes.

A reporter you have looked at and left alone is not raised again unless their
behaviour changes, so the queue stays small enough to be worth reading.

### `failed_jobs` — degraded

Different from everything above, which measure lateness. A failed job is a code
path that broke. Look at what actually failed rather than restarting anything:

```bash
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry all   # after fixing the cause
```

## Daily and weekly

**Daily** — glance at the dashboard. If everything is green, that is the whole
task.

**Weekly** — drain the review queue. This is the only routine work the platform
genuinely requires a person for, and it compounds: every confirmed decision
teaches the matcher the phrase that defeated it, so a queue that is worked
shrinks and a queue that is ignored grows.

**Before an upgrade** — take a backup (see
[deployment.md](deployment.md#backup-and-restore)), and afterwards run the
end-to-end proof above rather than trusting that the containers came up.

## Things that are supposed to happen

Not faults, and worth knowing so nobody spends an afternoon on them:

- **`cost_usd` is null for some snapshots.** No usable exchange rate within the
  horizon. Publishing an invented conversion would be worse.
- **Some basket items show `is_imputed: true`.** Estimated rather than observed,
  and labelled as such. This is what makes a thinly-covered location comparable
  with a well-covered one.
- **`coverage` below 1.0.** Part of the basket has neither an observation nor a
  usable estimate. The weight counts against coverage rather than the item being
  quietly dropped.
- **Submissions in the review queue at 0.8 confidence.** The matcher was fairly
  sure and not sure enough. Refusing to guess is the design.
- **A `duplicate` response to a submission.** A reporter's phone replaying its
  offline queue. Both the original and the replay are treated as success so the
  item leaves the device.
