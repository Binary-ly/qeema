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
| 3 — Reporter PWA | **Complete and verified** |
| 4 — Ingestion (partner + scrapers) | **Complete and verified** |
| 5 — Product matching | **Complete and verified** |
| 6 — Anomaly detection | **Complete and verified** |
| 7 — Index computation + FX | **Complete and verified** |
| 8 — Nowcasting / imputation | **Complete and verified** |
| 9–12 | Not started |

---

## Quality gates — current numbers

| Gate | Result |
|---|---|
| Pest | **335 passed**, 1 skipped, 1,473 assertions |
| PHP coverage | **93.6%** (gate ≥80%) |
| Playwright (offline E2E) | **8 passed** |
| pytest | **210 passed** |
| Python coverage | **86.0%** (gate ≥80%) |
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

## Phase 3 — verified

A mobile-first, installable PWA at `/report` that works with no connection.

**The honest architecture.** Livewire is server-driven and cannot function
offline, so the submission path is deliberately **plain Alpine against a JSON
endpoint**. Everything a reporter enters goes into an IndexedDB outbox first and
is only removed once the server has acknowledged it.

**Idempotency is enforced by the database, not by application logic.** Every
queued item carries a client-generated UUID, and `unique(reporter_id,
client_idempotency_key)` is what actually prevents a double-count. The action
also catches the constraint violation, because a read-then-write check races
under concurrent replay. A replay returns **200 `duplicate` with the original
id** — never a 4xx, which would leave the item stuck in the queue retrying
forever.

**Verified in a real browser** (Playwright, emulating a Pixel 5):

- shell and cached catalogue load and are usable with the network off
- a price entered offline is kept, then sent on reconnect
- three offline entries all survive and sync together
- the same payload sent twice yields `201 accepted` then `200 duplicate`, same id
- the interface renders `dir="rtl"` in Arabic, with the price field kept LTR
- the app is installable and the manifest resolves

**Bilingual and RTL.** Direction is derived from the *locale*, not a per-country
flag, so any country configured with an RTL language works unchanged. One
stylesheet serves both directions via CSS logical properties — a mirrored second
stylesheet would drift the first time someone edited one of them.

**Two details worth naming.** The scaffold's Instrument Sans has **no Arabic
coverage**, so the reporter uses a system font stack: it renders Arabic natively
everywhere and costs zero bytes. And the price field is pinned `direction: ltr`
inside the RTL layout, because a number is not bidirectional text.

Total reporter bundle: **18.2 kB gzipped** (CSS + JS), no webfont, no images.

**Known limitation, by design:** the app must be opened once *with* a connection
before it can survive losing one — a service worker does not control the page
that registered it. The UI says so when no cached catalogue exists.

---

## Phase 4 — verified

**Partner spreadsheets.** Streaming CSV/TSV/XLSX via OpenSpout, so a partner's
annual export does not turn one upload into an outage. The delimiter is sniffed
rather than assumed — semicolon-separated CSV is what Excel produces across much
of Europe and the Middle East, and reading it as comma-separated yields one
column and a wall of meaningless errors.

**Partial success is the normal outcome.** A file with 900 good rows and 100 bad
ones imports 900 and returns a per-row report naming the row, the column and the
value. Rejecting the file wholesale means the partner has to fix everything
before Qeema gets any of it, which in practice means Qeema never gets any of it.
Error reports are bounded so a wholly-broken file cannot produce a report nobody
can read.

**Formats real partners actually send**, all tested: comma decimal marks
(`6,50`), thousands separators (`1,250`), both together (`1,250.75`),
Arabic-Indic digits (`٦.٥٠`), currency symbols beside the number, Excel serial
dates, and day-first vs month-first dates. Headers are guessed in English *and*
Arabic — then confirmed by a human, because a silently misread column puts a
price against the wrong item.

**Re-uploading is a no-op.** A file checksum recognises the resend, and each row
carries a UUID v5 derived from that checksum and its row number, so the property
holds even if the batch check is bypassed.

**Scraper framework** with a contract requiring resumability, idempotency and
politeness — and a runner that enforces all three. The worked example targets
*openly licensed published datasets* rather than a shop's website: pointing an
example scraper at someone's storefront invites operators to take data nobody
offered and gets the deployment blocked. It checks robots.txt before fetching,
identifies itself honestly, declares a conservative rate, and persists its cursor
after **every page** so an interrupted run resumes instead of re-fetching against
a rate-limited endpoint.

**Admin upload page** with a two-step flow: guess the mapping, show the operator
the first rows and the guess, import only on confirmation.

---

## Phase 5 — ML core verified, integration outstanding

**Measured on the real 18-item / 133-variant Libya catalogue, 371 labelled queries:**

| Metric | Value |
|---|---|
| Top-1 accuracy | **98.4%** |
| Top-3 accuracy | 99.7% |
| Mean reciprocal rank | 0.991 |
| Auto-resolve rate | 36.1% |
| **Auto-resolve precision** | **99.3%** |
| Sent to review | 63.9% |
| Rejected | 0.0% |

Auto-resolve precision is the figure that matters: the share of matches accepted
*without a human* that were correct. The conservative 36% auto-resolve rate is
correct behaviour for an **uncalibrated** deployment — confidence is deliberately
shrunk toward 0.5 until real human decisions exist to calibrate against.

Published to `docs/model-cards/matching-evaluation.md` with its own caveat: these
are synthetically perturbed catalogue variants, not real submissions, and should
be read as an upper bound.

**Scorer chosen by measurement, not intuition.** The first implementation took
`max()` of three rapidfuzz scorers. Measured against genuine matches and
unrelated Arabic pairs:

| scorer | min(match) | max(non-match) | margin |
|---|---|---|---|
| token_set_ratio | 95 | 40 | **55** |
| WRatio | 90 | 72 | 18 |
| token_sort / ratio / QRatio | 35 | 40 | −5 |

`max()` of several scorers is *strictly worse* than the best single one, because
the maximum inherits the worst false-positive behaviour of its members —
`partial_ratio` scored **0.80** for "ارز" against "بوتاجاز", two unrelated words.
Arabic product names are short, so that would have produced confident wrong
auto-resolves.

**The normaliser is contract-bound.** The Python implementation passes the same
22 fixtures as the PHP one, so the two cannot silently drift.

### The Laravel boundary

`MlClient` with a consecutive-failure circuit breaker. **It never throws at the
caller.** A null return means "no opinion" — unreachable, errored, or circuit
open — which becomes a review-queue entry. It never means "no match": discarding
a valid observation because a container was restarting would be silent data loss
with no trace.

Degradation is tested directly: with the service down, submissions queue for
review and no observation is created. After the failure threshold the breaker
opens and the client provably stops issuing requests.

**Contract tests run on both sides of the same schema.** The PHP suite validates
the *fake* against `contracts/ml-match-response.json`; the Python suite validates
the *real* FastAPI responses against the identical file. That pairing is the only
thing preventing the classic mocked-boundary failure where a drifting double
keeps every test green until production.

**The review loop teaches the matcher.** Every approved decision becomes a new
`human_review` variant keyed on its normalised form, and invalidates the
catalogue cache so it takes effect immediately. A queue that only fixes the
submission in front of the reviewer never shrinks.

### Still outstanding in Phase 5

- Embedding generation and the pgvector semantic path end to end. The matcher
  supports it and is tested with a stand-in embedder, but no real embeddings are
  written, so **production matching is currently lexical-only** — and the 98.4%
  figure above is a lexical-only result.
- A Filament screen for the review queue. The actions behind it (`approve`,
  `reject`) are built and tested; only the UI is missing.

---

## Phase 6 — verified

**Measured against 10,675 labelled observations** (10,099 clean, 576 labelled bad):

| Error type | Recall |
|---|---|
| unit_confusion | **100%** |
| decimal_slip | **100%** |
| wrong_currency | 99.3% |
| stale_copy | 12.8% |
| coordinated manipulation (observation-level) | 5.3% |

Overall 68.6% recall, 74.8% precision, **1.3% false-positive rate on clean data**.
The false-positive rate matters as much as recall: a detector that flags
everything has perfect recall and no value.

### The statistic mattered more than the model

5.3% on manipulation is structural, not a tuning problem — every manipulated
price is individually plausible, so no per-observation test can separate it from
a genuinely cheaper shop.

A reporter-level layer was added, and **scored 0%**. Manipulators had a median
price ratio of 0.995 against honest reporters' 1.001: no separation at all. The
cause is that only **~12% of a manipulator's submissions are falsified**, so the
median is dominated by their honest majority — a *robust* statistic that is
robust against exactly the signal being hunted.

The **lower decile**, where partial manipulation lives, separates cleanly:

| Manipulation detection | Median | Lower decile |
|---|---|---|
| Recall | 0% | **100%** (4 of 4) |
| Precision | — | 80% (1 false positive) |
| Separation | none | manipulators z −22…−14; next honest −2.2 |

Same data, same model, one statistic changed. Published in
`docs/model-cards/anomaly-evaluation.md`.

### Design choices worth stating

Hard bounds **permit a genuine 40% supply shock** — tested explicitly. A detector
that discards those discards the signal the platform exists to publish.

Layers combine with a **maximum, not an average**: averaging would let two quiet
layers dilute one that is certain.

`rejected` invalidates an observation but never deletes it; `suspect` keeps it
valid and asks a human. Flagged *reporters* go to review, never to automatic
rejection — accusing someone of manipulation on statistical evidence and
silently discarding their work is a decision a person should make.

**Unscored is not clean.** When the ML service is unavailable the pipeline
records nothing rather than a clean verdict, which would let bad data through
precisely when the system is least able to notice.

---

## Phase 7 — the platform produces its first index figure

Real data, real basket, computed across three locations for the same day:

| Location | cost (LYD) | USD | coverage | interval | quality | comparable |
|---|---|---|---|---|---|---|
| Tripoli | 2,954.81 | 344.36 | 95.0% | [2,925, 3,032] | good | no |
| Sabha | 2,570.70 | 299.60 | 88.0% | [2,325, 2,707] | moderate | no |
| Ghat | 3,516.94 | 409.87 | 78.0% | [3,476, 3,857] | moderate | no |

Converted at the **parallel** rate (8.5805), with a bootstrap confidence
interval and weight-based coverage.

### A comparability trap, found by looking at the output

Sabha reads *cheaper* than Tripoli despite carrying a regional premium — because
at 88% coverage the missing 12% of the basket is simply **not counted**. A reader
would conclude Sabha is cheaper when it is merely less observed, and thin
coverage usually accompanies harder conditions rather than cheaper ones.

This is the exact failure mode the platform must not have. Two responses:

- `qualityLabel()` was too generous — a basket missing a fifth of its weight
  read as "good". Anything above 10% imputed is now at best "moderate".
- `isComparable()` was added and is false until every basket item has a price.
  Consumers ranking locations have to check it, rather than the trap being left
  for them to fall into.

The real fix is Phase 8: imputing the missing items so every basket is complete
and costs *are* comparable. Until then, `cost_local` is the cost of the observed
part of the basket, and the API must say so.

### Design decisions

**Weighted median, not mean.** Crisis price data is heavy-tailed; a mean is
dragged by exactly the values that should count least.

**Recomputation is deterministic.** Bootstrap seeds are derived from
(location, item, date), and reputation is frozen on the observation at
ingestion — so recomputing an old snapshot reproduces it exactly rather than
drifting because a reporter's score changed since.

**Basket intervals are one joint draw**, not a sum of per-item bounds, which
would be far too wide.

**Stale FX is used but always flagged**, and refused beyond the country's
configured horizon — `cost_usd` is published as null rather than converted at a
rate nobody can stand behind.

### Correction ripple — the phase's acceptance criterion

A `PriceObservation` observer marks every snapshot in the estimator's look-back
window stale when an observation is created, invalidated, superseded or has its
price changed. Marking the observation's *own day* only would leave a week of
published figures silently wrong after a correction — worse than never
correcting, because the error is then dated and invisible.

Verified end to end on the seeded data: rebuilt 48 snapshots, invalidated one
historical observation, watched exactly one snapshot go stale, drained the queue
with `qeema:index`, and confirmed 0 pending. A unit test asserts the same in
miniature — a price entered per gram instead of per kilo, superseded, and the
published cost moving 200.00 → 20.00 with no duplicate snapshot.

Marking is deliberately generous: recomputing a snapshot that did not need it
costs a little work, while missing one leaves a wrong number published forever.
Unrelated column changes are excluded, so touching a currency code does not
trigger a week of recomputation.

**26 dedicated tests** cover the weighted median (hand-computed expectations,
including weight-beats-count and outlier-immunity), window inclusion, weight-based
coverage, interval bracketing, determinism across recomputation, and every FX
path — exact, stale fallback, refusal beyond the horizon, never-use-a-future-rate,
and null-rather-than-unconverted.

### Still outstanding in Phase 7

- Chain-linking across basket versions, so a basket definition change does not
  create an artificial discontinuity in the published series.

---

## Phase 8 — verified

**Backtested on 27,650 held-out cells**, split temporally rather than randomly —
a random split lets the model see prices from the week it is asked to predict,
which in a series this autocorrelated is close to showing it the answer.

| Metric | Value |
|---|---|
| MAPE | **3.5%** |
| Median APE | 2.7% |
| National-median baseline | 9.0% |
| **Improvement over baseline** | **+61%** |
| Mean interval width | 10.9% |

The baseline is reported alongside deliberately: a model that cannot beat the
national median is not worth its complexity.

### Known weakness: the interval under-covers

Empirical coverage is **74.6%** against a nominal **80%**. Roughly one true value
in twenty falls outside a band advertised as containing four in five.

Not catastrophic, but the wrong direction to be wrong in — an interval should
over-cover, because a reader trusting an 80% band is entitled to have it hold at
least that often. Two remedies are documented in the model card (widening the
trained quantiles to 0.05/0.95, or a conformal correction); neither is applied
because both require re-measurement rather than assertion. Until then the card
states plainly that the interval should be read as roughly 75%.

### This is what makes locations comparable

Before imputation, `cost_local` priced only the *observed* part of the basket, so
a location at 78% coverage read as **cheaper** than one at 95% — exactly
backwards, since thin coverage usually accompanies harder conditions. Missing
items are now filled and the cost is complete.

Design commitments, each tested:

- **Every imputed value is flagged.** `is_imputed` is true on that code path with
  no branch that can produce an unlabelled value, `observation_count` is 0, and
  `source_observation_ids` is empty. A test asserts observed and imputed rows can
  never be confused.
- **The basket interval samples the imputation's own interval**, so uncertainty
  reflects imputation error rather than sampling noise alone — which would be
  badly wrong on a largely-imputed snapshot.
- **It refuses rather than guesses.** With the ML service unavailable the basket
  stays honestly partial: no imputed rows, weight still counted against
  coverage. A silently completed basket would be worse than an openly partial
  one, because nothing would indicate the numbers were invented.
- **Targets are ratios to the national median**, so one model serves every item
  regardless of price scale — predicting absolutes would need a model per item
  and would relearn inflation as signal every month.

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
- **The Postgres session timezone was `Africa/Tripoli`, not UTC.** Laravel
  writes timestamps without an offset, so they were being interpreted as local
  time — a two-hour shift that would bucket observations near midnight into the
  wrong day and move the published index. Now pinned via the connection config.
- **The demo `APP_KEY` decoded to 31 bytes, not 32.** AES-256 rejected it, so
  the admin panel 500'd while the public API worked fine.
- **A test factory eventually collided with real data.** `CountryFactory`
  generated sequential two-letter codes and, after ~297 calls in one process,
  reached `LY` — the code the suite seeds — failing on a unique constraint with
  a message pointing nowhere near the cause. It now skips codes already present.
- **`value(DB::raw(...))` silently returns null**, because it looks the result
  up by the raw SQL string as a column name. The median reference was quietly
  null for every observation until it was checked against real data.
- **An unknown matched item violated a foreign key.** When the ML service named
  an item this deployment does not have — a stale catalogue cache — the id was
  still written to `resolutions.canonical_item_id`, turning a recoverable
  mismatch into a 500.
- **Absent evidence was treated as evidence of absence.** With
  `semantic_weight=0.6` and no semantic index — the state every new deployment
  starts in — a *perfect* lexical match of 1.0 fused to 0.40 and was rejected.
  Measured on the real catalogue that discarded **63.9% of correct matches**
  while top-1 accuracy read 98.4%: the matcher was finding the right answer and
  throwing it away. Weights are now renormalised over the signals that actually
  ran.
- **The evaluation harness mislabelled its own perturbations.** Dropping a hamza
  and writing Arabic-Indic digits both normalise back to the canonical form, so
  those queries deduped together and whichever ran first kept the label — the
  reported "hamza_dropped: 100%" was really "canonical form: 100%". Now labelled
  `absorbed_by_normalisation`, which is what it actually measures.
- **Carbon throws instead of returning `false`** from `createFromFormat` on a
  mismatch, so a `!== false` check never fired and one odd date failed a
  partner's entire upload.
- **`hash_file()` on a missing path raised before a batch existed** to record
  the failure on — a 500 rather than the actionable report the phase promises.
- **Generated redirects dropped the port** (`http://localhost/admin/login`),
  because Laravel builds URLs from the request rather than `APP_URL`. In the
  demo stack that turned "open the admin panel" into a connection refused.

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

### Phase 9 — Public API with OpenAPI 3 — complete

Nine read endpoints plus one write, all unauthenticated (C6), rate limited per
IP, with bulk CSV export held to a tighter limit than ordinary reads.

Verified live against a running server: every endpoint 200s, the CSV export
streams, and it carries `X-Qeema-License: CC-BY-4.0` — a downloaded file
outlives the page that explained its terms.

**The spec is generated, not hand-maintained.** `php artisan qeema:openapi`
scans annotations into `public/openapi.json`; `--check` fails if the committed
file has drifted, and CI runs it. A spec that has quietly diverged from the code
is worse than none, because consumers build against it.

Served at `/api/v1/openapi.json`, rendered at `/docs` from the spec with no
external assets. A docs page that fetches its renderer from a CDN would breach
C1 and would be blank in exactly the low-connectivity settings this platform
targets.

Three schema decisions are enforced by test rather than left to convention:

- `is_imputed` is **required** on every priced item. Were it optional, a
  consumer could reasonably read its absence as "observed" — the precise
  confusion between estimate and measurement the platform exists to prevent.
- `comparable`, `coverage` and `imputed_share` are required on `quality`.
  Ranking locations without checking `comparable` produces the inverted result
  found in Phase 7, where a thinly-covered location read as cheaper.
- `cost.usd` is documented **nullable**. Null means no exchange rate inside the
  staleness horizon existed — a refusal to invent a conversion, not missing data.

| Gate | Result |
|---|---|
| Pest | 358 passed, 1 skipped, 1,567 assertions |
| Coverage | 93.7% |
| PHPStan (level 6) | 0 errors |
| Pint | passed |

**Carried forward:** matching remains lexical-only, so the 98.4% top-1 figure is
a lexical result; pgvector embeddings are wired but not yet generating. Nowcast
intervals under-cover (74.6% empirical against 80% nominal), documented in the
model card with two untried remedies. Chain-linking across basket versions and
the Filament review-queue UI both still outstanding.

### Phase 10 — Public dashboard — complete

Server-rendered at `/`, bilingual EN/AR with true RTL, no third-party asset of
any kind. Every figure is in the markup before a byte of JavaScript executes.

**A published-data bug surfaced while wiring the headline, and it was the
serious kind — wrong numbers on the public API, not a broken page.**

`IndexCalculator` counted an unpriced basket item as *imputed* weight whether or
not anything had actually imputed it: the increment sat above the null check.
Two consequences, both of which had shipped:

- `imputed_share` claimed estimation that never happened. Locally, with no ML
  service running, snapshots reported 22% of the basket "estimated" when 22% was
  simply missing and excluded from the cost.
- `coverage_pct + imputed_share` therefore summed to exactly 1.0 on every
  snapshot, leaving no way to distinguish a complete basket from a broken one.

`isComparable()` then compounded it. It read `imputed_share <= 0.0` — accidentally
correct only while nothing was ever imputed. Once Phase 8 began filling gaps,
**46 of 48 published snapshots reported `comparable: false`**, including baskets
that were fully priced. The public API was telling consumers not to compare
almost anything.

Fixed in three places, each with a regression test naming the failure:

| | |
|---|---|
| `IndexCalculator` | counts imputed weight only when an imputation actually succeeded |
| `IndexSnapshot::isComparable()` | fully *priced* — observed **or** imputed — not fully observed |
| `IndexSnapshot::qualityLabel()` | tests `missingShare()` explicitly; an incomplete basket with zero imputation was passing as "good" once `imputed_share` became honest |

Imputation is what makes a sparse location comparable, so a heavily-imputed
basket is comparable — just less certain, which `imputed_share` and the quality
label are there to say. Treating any imputation as disqualifying discards the
very locations imputation exists to serve.

**Map: inline SVG, not MapLibre GL.** PLAN.md §7.4 said otherwise; D-10 records
why that changed. Locations are points, not polygons, so a WebGL engine meant
~230 kB gzipped to draw sixteen circles — and a canvas contributes nothing to
the accessibility tree, so a parallel table would have been needed regardless.
Every point is now a focusable, labelled DOM element that works with JavaScript
off.

**Bundle.** The critical path is 1.65 kB + 1.89 kB gzipped (script + styles).
ECharts loads lazily behind an IntersectionObserver. The first attempt imported
`echarts/charts` and `echarts/components` dynamically, which loads the barrels
whole — 84 kB gzipped of unused chart types plus a 245 kB GeoJSON parser. Named
static imports inside a lazily-imported module fixed it.

Also fixed: Blade's directive parser silently truncates a multi-line array
literal passed to `@json(...)`, which emitted malformed JSON; and it compiles
directive names appearing inside `{{-- --}}` comments.

| Gate | Result |
|---|---|
| Pest | 387 passed, 1 skipped, 1,664 assertions |
| Coverage | 93.9% |
| PHPStan (level 6) | 0 errors |
| Pint | passed |

**Not yet measured: Lighthouse.** The ≥90 performance and accessibility targets
are designed for — server-rendered, no render-blocking third-party assets,
deferred charts, skip link, labelled sections, table headers, no colour-only
signalling — but no Lighthouse run has been performed, because it needs a
headless Chrome this environment does not have. Deferred to Phase 12 and stated
here rather than claimed.

**Carried forward:** imputation does not run without the ML service, so the
local demo currently shows every location as incomparable — correct behaviour,
but `docker compose up` is needed to see the imputed path. Matching remains
lexical-only. Nowcast intervals under-cover (74.6% against 80% nominal).
Chain-linking across basket versions and the Filament review-queue UI still
outstanding.

### Phase 11 — Self-hosting and a second country — complete

**Venezuela ships alongside Libya**, chosen because it differs along every axis
the code could plausibly have hardcoded: Spanish not Arabic, **LTR not RTL**,
Latin script (so the Arabic normalisation path is bypassed rather than
exercised), two-decimal currency against the dinar's three, western hemisphere
(negative longitudes), and a different staple — the basket is built on precooked
maize flour, because a wheat-centred basket would be measuring the wrong thing
there.

A second country resembling the first would have proved very little.

`CountryAgnosticismTest` now enforces this structurally rather than by grepping
for "Libya": baskets must sum to 1.0 and cover all nine categories in every
country, the shipped set must actually contain more than one locale and more
than one currency subdivision, longitudes must span both hemispheres, estimator
settings must differ between countries, and **every configured locale must have
a complete translation file** — a locale with no translations renders English
under a `lang` attribute claiming otherwise. Spanish translations were added for
both the dashboard and the reporter.

**Three bugs found by actually running the clean boot, not by reading code.**

1. *Adding a country to a running deployment did nothing, silently.* The
   bootstrap guard was `DB::table('countries')->exists()` — any one country
   short-circuited the whole reference seed. Since the config files themselves
   promise that adding a country means adding a file and no code change, an
   operator had no way to distinguish "ignored" from "broken". Both guards are
   now per-country.
2. *Adding a second country aborted the bootstrap.* The demo generator
   plain-inserts FX rates rather than upserting, so re-running it over an
   already-seeded country died on a unique violation. The demo seeder now skips
   countries that already have a history.
3. *`make demo` seeded data but never computed the index.* Every endpoint
   returned 200 with nothing in it — the most misleading way for a demo to fail,
   and a direct C2 breach: the constraint asks for a system that *works* after
   one command, not one that is merely running. Bootstrap now publishes the
   index over the demo window.

**C2 verified, not assumed.** `make nuke && make demo` from zero volumes:

| | |
|---|---|
| Clean boot | 1m31s (cached images); 7m11s first run |
| Seeded | 2 countries, 22,424 submissions, 21,297 observations |
| Published | 992 snapshots (2 × 16 locations × 31 days) |
| Comparable | **992 of 992**, mean imputed share 16.9% |
| Libya | `lang=ar dir=rtl`, headline 3,202.41 LYD, 16 rows |
| Venezuela | `lang=es dir=ltr`, headline 34,471.41 VES, 16 rows |
| CSV export | 497 rows, `X-Qeema-License: CC-BY-4.0` |

That comparability figure also closes out the Phase 10 finding: with the ML
service actually running, imputation fills the baskets and every location
becomes comparable at an honest 16.9% estimated share. The local runs that
showed everything incomparable were correct — they simply had no ML service.

**`docs/deployment.md`** covers requirements, the one-command demo, a full
configuration reference, adding a country, production hardening, backup and
restore, upgrade and rollback, and troubleshooting.

Its first draft named **nine environment variables that do not exist**. Every
one looked plausible. `DeploymentDocsTest` now fails the build if the guide
names an env var the application never reads, omits one it does read, quotes the
wrong demo password, or references a Makefile target or artisan command that
does not exist. Writing that test also turned up a documented knob
(`QEEMA_EXPORT_RATE_LIMIT`) that was hardcoded — now genuinely configurable.

| Gate | Result |
|---|---|
| Pest | 401 passed, 1 skipped, 1,707 assertions |
| Coverage | 93.9% |
| PHPStan (level 6) | 0 errors |
| Pint | passed |

**Carried forward:** the new bootstrap index step is verified by the clean-boot
run above but has no unit test yet. Lighthouse still unmeasured. Matching
remains lexical-only. Nowcast intervals under-cover (74.6% against 80%
nominal). Chain-linking across basket versions and the Filament review-queue UI
outstanding.

### Phase 12 — Hardening — complete

**Lighthouse, finally measured.** Deferred from Phase 10 rather than claimed;
run here on throttled mobile (4× CPU, slow 4G), not the generous desktop preset:

| Page | Performance | Accessibility | Best practices | SEO | LCP |
|---|---|---|---|---|---|
| Dashboard (LY, RTL) | 99 | 100 | 100 | 100 | 1.0 s |
| Dashboard (VE, LTR) | 97 | 100 | 100 | 100 | 2.3 s |
| Reporter | 100 | 100 | 100 | 100 | 1.2 s |
| API docs | 100 | 100 | 100 | 100 | 0.9 s |

Target was >90 on performance and accessibility; the lowest of either across
all four pages is 97. Two pages initially scored 91 on SEO for a missing meta
description, now added.

**Coverage, both services:** PHP 94.0% (409 passed, 1 skipped, 1,726
assertions), Python 86.0% (190 passed). C5 met on both sides.

**End-to-end: 23 Playwright tests** against the composed stack. The eight
existing reporter tests are joined by fifteen covering the public surface —
what a reviewer opens and a consumer builds on. They assert properties rather
than markup: that the API needs no credentials, that `is_imputed` is a boolean
on every item and imputed items carry a method and zero observations, that
coverage plus imputed share never exceeds the basket, that `cost.usd` is a real
number or explicitly null and never a silent conversion, that the CSV carries
its licence, that the map is keyboard-reachable, and that **no request leaves
localhost** on any page.

**Security pass.** Findings on the running stack: headers present, admin gated
(302), Horizon 403, no debug leak, SQL-injection probes returned clean 404s, no
XSS reflection, rate limiting enforced (429 after 5 exports/min). Two gaps
closed — a Content-Security-Policy and a Permissions-Policy, neither of which
existed.

**The CSP broke the reporter, and that is how the real problem was found.**
Adding a strict `script-src 'self'` made all eight reporter end-to-end tests
fail at once. Two distinct causes, and only one was unavoidable:

1. Alpine compiles its `x-` expressions with `new Function()`, so it genuinely
   requires `'unsafe-eval'`. Rather than weaken the policy everywhere, the
   exception is scoped to the routes that run Alpine — the reporter, admin and
   Horizon. The public dashboard, API, docs and CSV export keep the strict
   policy, which is what a passer-by and a data consumer actually touch.
2. An inline `<script>` block registered the service worker. That one *was*
   avoidable: it moved into the bundled entry point, so the reporter now needs
   no `'unsafe-inline'` at all. A test asserts the absence of any inline block,
   because re-adding one would silently force the keyword back.

The dashboard's country picker lost its inline `onchange` for the same reason.

**Performance pass on the index query path.** Warm p50 is 28–50 ms across the
index endpoints and both dashboards. The plan was the more interesting result:
`GET /index/current` resolves a per-location maximum date, which the existing
`(country_id, snapshot_date)` index does not serve, so Postgres sequentially
scanned every snapshot in the country to compute the aggregate. Harmless at
992 rows and linear in history.

Two changes, both measured on a 35,712-row table (three years, two countries):

- A `(country_id, location_id, snapshot_date DESC)` index.
- `DISTINCT ON` in place of a join against a grouped subquery, at both call
  sites. **3.35 ms against 4.32 ms** — a single ordered index walk rather than a
  sequential scan, a hash aggregate and one index probe per location. Postgres
  has no loose index scan, so the index alone did not help the aggregate; the
  query had to change with it. Simpler code, too.

| Gate | Result |
|---|---|
| Pest | 409 passed, 1 skipped, 1,726 assertions, 94.0% |
| pytest | 190 passed, 86.0% |
| Playwright | 23 passed against the composed stack |
| PHPStan level 6 | 0 errors |
| Pint / ruff / mypy | clean |

**Model cards** published for all three ML components under `docs/model-cards/`:
matching (98.4% top-1, 99.3% auto-resolve precision), anomaly detection (100%
recall on unit confusion and decimal slip, 99.3% wrong currency, 12.8% stale
copy, 100% manipulation recall at 80% precision), and nowcasting (MAPE 3.5%
against a 9.0% baseline, +61%).

**Still open, and stated rather than buried:**

- **Matching is lexical-only.** pgvector is wired and the semantic path is
  implemented, but embeddings are not being generated, so the published 98.4%
  top-1 is a lexical-only result. This is the largest gap between what the
  architecture claims and what runs.
- **Nowcast intervals under-cover:** 74.6% empirical against 80% nominal. Two
  remedies are documented in the model card; neither is applied because both
  need re-measurement.
- **Alpine still needs `'unsafe-eval'`** on three routes. Alpine's CSP build
  would remove the exception at the cost of rewriting every inline expression
  as a component method.
- Chain-linking across basket versions, and the Filament review-queue UI (the
  actions are built and tested; the screen is not).
- The bootstrap index step is verified by clean-boot runs but has no unit test.

### First real CI run — four latent failures

The repository had no remote until it was pushed, so the workflow had never
executed. Every gate had only ever been run locally, where a `.env` and built
assets happen to exist. Four failures surfaced at once, none of them caused by
the code they were testing:

1. **The workflow file did not parse**, so no job ran at all (0s, no
   annotations). A readiness check ended
   `grep -q '"models_loaded": *true'` as a plain scalar; the `: ` inside it made
   YAML read a nested mapping, and the inner quotes were part of the string
   rather than quoting it. Now a block scalar.
2. **57 tests failed with `MissingAppKeyException`.** There is no `.env` in CI,
   so encryption was unconfigured and anything rendering a page died. Fixed in
   `phpunit.xml` with a fixed 32-byte test key rather than in the workflow, so a
   fresh clone can run the suite without any setup — the same failure a new
   contributor would have hit.
3. **35 tests failed on `Vite manifest not found`.** `public/build` is generated
   and gitignored, and the PHP job never built it, but Blade calls `@vite()`.
   The job now runs `npm ci && npm run build`.
4. **The C2 demo job ran out of disk.** The ML image bakes ~1.1 GB of weights on
   top of CPU torch (D-09) and does not fit beside the runner's preinstalled
   toolchains. The job now reclaims ~20 GB first, rather than weakening the
   constraint that the model ships in the image.

Also caught by the constraint job: a `'LYD'` example had leaked into the
OpenAPI definition — a C3 violation in the *published contract*, which is the
worst place for one. Now `XTS`, the ISO reserved-for-testing code.

All four jobs green.

---

## Phase 13.1 & 13.2 — closing the loop — complete

Plan: `docs/plan-close-the-loop.md`. Sub-phases 13.3–13.6 (review queue, FX
ingestion, observability, end-to-end proof) remain open.

### What was actually broken

Every stage of the ingestion pipeline was built, unit-tested — and unreachable.
A price posted to the public API was written with status `pending` and stayed
there permanently. Verified on the running stack before any change:

```
POST /api/v1/submissions  → 200 {"submission_status":"pending"}
submissions.status        → pending (unchanged after ten minutes)
resolutions               → 0 rows
price_observations        → 0 rows
redis queue               → 0 pending, 0 delayed
```

Three actions had no production caller at all: `ResolveSubmission`,
`ScoreSubmissionAnomaly`, `ApplyReviewDecision`. There was no scheduler —
`routes/console.php` held only Laravel's stock `inspire` — so `qeema:index`
was a command nobody ran, and nothing created a snapshot for a new calendar day
in the first place. The demo was convincing because `SyntheticDataGenerator`
bulk-inserts the pipeline's *outputs*: submissions, resolutions, observations
and anomaly scores, all written directly.

The invariant this establishes: **every submission reaches a terminal state,
and every valid observation reaches a published snapshot, within a bounded and
monitored time — or an operator is told why not.**

### Decisions

**D-11 — a fast path *and* a reconciler.** Dispatch-on-write gives the latency
"live" implies; `qeema:pipeline:sweep` every minute is the guarantee.
`RecordSubmission` is not the only writer: `PartnerFileImporter` inserts with
the query builder, so no model event fires, and any future importer will be
written by someone who has not read this file. The sweeper is also why the
eleven stranded submissions needed no migration script — they were simply the
first tick's work.

**D-12 — the job waits out an ML outage rather than converting it into human
work.** `ResolveSubmission` routes to review when the matcher has no opinion,
which is right for a direct call and a bad trade as the automatic response to a
container restart: a thousand submissions that would each have resolved in
milliseconds become a thousand items of human work. The deferral therefore
lives in the job, on a `[10, 30, 120, 300]` ladder, leaving the action's tested
semantics untouched. The final attempt falls through deliberately — waiting for
ever is not an option either.

**D-13 — the scheduler is its own container.** A scheduler that dies inside the
worker leaves `docker compose ps` reporting a healthy worker while the index
quietly stops updating, which is exactly the invisible failure it exists to
prevent. It reuses the app image, writes a heartbeat every minute, and its
healthcheck reads that heartbeat back.

**D-14 — "today" is a per-country question.** One deployment runs in
`Africa/Tripoli` and another in `America/Caracas`, eleven hours apart. A
server-local date publishes tomorrow's snapshot early in one country and
yesterday's late in the other, so `qeema:index:publish` computes the date in
each country's own timezone and normalises the instant to UTC midnight so
recency weights stay reproducible. C3 is not only about literals in code; a
hardcoded notion of *when* is the same mistake in a different dimension.

**D-15 — a publish grace window.** An observation marks its snapshots stale
inside the transaction that creates it; screening lands a moment later. A drain
in that gap publishes a figure containing an unscreened price and corrects it
seconds afterwards. Self-correcting, and briefly wrong in public. `qeema:index`
now takes `--grace` (default 60s), backed by a new `stale_marked_at` column
that also gives backlog *age* — the signal that actually distinguishes a
backlog being worked through from a pipeline that has stopped.

**D-17 — bounded retries end in the review queue, never in silence.** The retry
budget lives on the submission row (`pipeline_attempts`), not on the queue
message, so it survives re-dispatch: five attempts means five however many
times the work was queued. After that the submission is handed to a human with
the error attached.

### Changed

Jobs `ResolveSubmissionJob`, `ScoreSubmissionAnomalyJob`,
`ResolveIngestionBatchJob`; commands `qeema:pipeline:sweep`,
`qeema:index:publish`, `qeema:scheduler:heartbeat`; the schedule itself; a
`scheduler` compose service; two migrations; `--grace` on `qeema:index`.

**Horizon was running on vendor defaults** — there was no `config/horizon.php`,
so a single supervisor served only the `default` queue. Published and tuned:
`pipeline-live` and `pipeline-bulk` supervisors, so a fifty-thousand-row
partner spreadsheet cannot decide how long a reporter waits. `retry_after`
raised to 300s, above every job and supervisor timeout.

**The test suite no longer runs queued work by default.** Under `sync`, every
test that created a submission silently executed the whole resolution path,
attempted real HTTP to an unreachable host, and left circuit-breaker state in
the shared array cache for whatever test ran next. `QUEUE_CONNECTION=null`;
tests that want the pipeline opt in with `Queue::fake()` or `dispatchSync`.

### A latent defect the loop exposed

Wiring the pipeline made the first ever production call to the anomaly
endpoint, which returned **HTTP 500**:

```
AttributeError: 'AnomalyVerdict' object has no attribute '__dict__'
```

`AnomalyVerdict` is `@dataclass(frozen=True, slots=True)` and has no instance
dictionary; the endpoint built its response with `v.__dict__`. It had never
worked. A hundred anomaly tests passed throughout, because every one of them
exercised the detector directly and **not one went through HTTP** — the same
shape of gap as a pipeline stage with no caller. Fixed with `asdict()`, plus
six tests that cross the boundary.

The same pass found that `anomaly_scores.model_version` was always null: the
service reports its version once on the envelope and the caller stored it per
verdict. A past verdict that cannot be attributed to a model version is most of
an audit trail missing.

### Verified on the running stack

Not argued — run. `docker compose up -d`, six containers healthy including the
new scheduler:

| | |
|---|---|
| Stranded backlog | 11 pending adopted by the first sweep: 10 auto-resolved, 1 matched at 0.80 confidence and routed to review rather than guessed |
| Submitted | 20:42:56 — `cooking_oil_1l`, 13.75 LYD, Tripoli, through the public API |
| Resolved | automatically, method `fused`, confidence 0.99 |
| Published | **20:44:10 — 74 seconds, no command run by anyone** |
| Screened | verdict `clean` |
| Public API | `observation_count` 3 → 4, `is_imputed` false |

### Gates

| Gate | Before | After |
|---|---|---|
| Pest | 409 passed, 94.0% | **479 passed, 1 skipped, 1,913 assertions, 94.3%** |
| pytest | 190 passed, 86.0% | **196 passed, 83.1%** |
| PHPStan level 6 | 0 errors | 0 errors |
| Pint / ruff / mypy | clean | clean |
| C3 country-agnostic | pass | pass |

The test that would have caught the original gap is
`tests/Feature/Pipeline/ClosedLoopTest.php`: published snapshot → POST a price →
resolved → observed → screened → marked stale → recomputed → visible on the
public API. A test of a stage cannot see a missing wire, so it walks the wire.
`tests/Feature/Console/ScheduleTest.php` is the companion guard — it asserts the
schedule itself, because the absence of a scheduled task is invisible to a test
of the task.

### The compliance job caught a C3 violation the local check had missed

CI failed on a timezone name — `Africa/Tripoli` — used as an *example inside a
doc comment* explaining why dates must be per-country. The irony is the point:
the comment argued against hardcoding a country and hardcoded one to do it.

The interesting part is why `make check-country-agnostic` passed locally
moments earlier. The script uses `git grep`, which searches **tracked files
only**, and the file was still untracked. So the guard was lenient about
exactly the code most likely to break the rule: code that has just been
written. `--untracked` added, and verified by planting a violation in an
uncommitted file and watching the check fail.

---

## Phase 13.3 — the review queue — complete

The last place the loop was still open. Everything the matcher resolves
confidently now reaches the published index on its own; everything it declined
to decide landed in `needs_review`, and `ApplyReviewDecision` had **zero
production callers** — the same condition that made the whole pipeline dead two
days ago. The actions were written and tested. The queue had no door.

**Admin → Ingestion → Review queue**, with the backlog size on the navigation
item. List-only by design: reviewing is repetitive triage, so everything needed
to decide is in the row — the text as typed, the matcher's suggestion and its
confidence, the screening verdict and reasons, the reporter's history, and the
basket weight of the item — and the modals are confirmation rather than a
second screen to read.

Three things the design turns on:

- **Approving teaches the matcher.** Already true in `ApplyReviewDecision`;
  what was missing was anyone able to trigger it. This is the mechanism by
  which the queue shrinks instead of refilling.
- **Bulk approve the suggestion.** The dominant case is the matcher having been
  right and merely unsure. Without a bulk path the queue is not drainable by
  one person, which is the same as not being drainable. Skipped and unusable
  rows are counted back to the reviewer rather than silently dropped.
- **Sort by basket weight.** An hour spent on heavy items corrects more of the
  published figure than an hour spent on light ones, so the impact ordering is
  a column rather than a doctrine.

### A defect the screen exposed before anyone used it

`ApplyReviewDecision::approve()` called `createObservation()`, ignored a null
return, and marked the submission `resolved` regardless. A submission that
could not be normalised to a price per base unit therefore came out of review
looking published, with no observation behind it and nothing reaching the
index — and the reviewer had every reason to believe they had published it.

Now it throws `SubmissionNotObservable`, the whole decision rolls back, and the
screen says so in words the reviewer can act on. Worth recording precisely
because the obvious guess about the trigger was wrong: an *unknown unit* does
not reach it, since resolution falls back to the item's default unit. The
reachable cases are a quantity that yields no price per base unit and a
misconfigured base unit — narrow, and silent exactly when it happens.

### Measured, not assumed

The queue's page query at the demo stack's real backlog of 1,137 rows:

| | Plan | Warm |
|---|---|---|
| Before | bitmap heap scan over every matching row, then top-N sort | 2.24 ms |
| After `(status, observed_at)` | index scan that stops after one page | 0.40 ms |

Both are fast. The plan is the point: one is proportional to the page and the
other to the backlog, and a review queue that gets slower the more it has to
review is a review queue that stops being used. A cold-cache first run measured
126 ms, which is the number an operator would actually have met.

### Gates

| Gate | Result |
|---|---|
| Pest | **499 passed**, 1 skipped, 1,973 assertions, **94.2%** |
| PHPStan level 6 | 0 errors |
| Pint | clean |

Twenty of those tests are the queue itself, and they assert the loop through a
human rather than the markup: that a decision produces a published observation,
teaches the matcher, moves the reporter's standing, and marks the affected
snapshot for recomputation — because a decision that does only some of those is
worse than none, since it looks complete.

**Still open:** 13.4 FX ingestion, 13.5 observability and the runbook, 13.6 the
Playwright loop test against the composed stack.

---

## Phase 13.5 — observability and the runbook — complete

**Every way this platform fails looks like silence.** There is no error page when
the index stops updating: the API answers, the dashboard renders, the containers
report healthy, and the published figures quietly stop moving. That is the right
behaviour for the people reading the data and it means somebody has to go
looking — so now something does.

Eight checks, each phrased as an invariant the pipeline promises and each with an
operational answer to *if this is not ok, what does an operator do?* A signal
nobody can act on is noise that trains people to ignore the ones they can.

| Check | Degrades when | What it means |
|---|---|---|
| `scheduler` | heartbeat older than 3 min | **stalled**, not degraded — the clock stopped and everything else is downstream |
| `resolution` | oldest pending submission past the alert window | dispatch *and* the sweeper are both failing |
| `recomputation` | oldest stale snapshot past the window | corrections are not reaching published figures |
| `publication` | a country has no figure for its own today | the roll-forward is not running |
| `exchange_rates` | newest rate past the country's horizon | dollar figures are being withheld |
| `review_queue` | oldest waiting submission past 7 days | the queue has an owner in theory only |
| `matching` | circuit open | everything is routing to human review |
| `failed_jobs` | any in 24h | something is broken rather than behind |

**D-18 in practice.** `/api/v1/health` gains a `pipeline` block of states and
ages; the counts stay behind the admin login. "1,412 awaiting review" tells an
honest observer very little and tells someone probing for a manipulation window
how thin the screening currently is. The block deliberately does not affect the
HTTP status — this endpoint backs the container healthcheck, and a pipeline that
is merely behind must not get the web container restarted underneath it.

Also shipped: a dashboard widget carrying the numbers, `qeema:pipeline:health`
on the schedule every five minutes with structured warnings into the log, and
`docs/operations.md` — the runbook, one section per signal, with the commands.

### It found something on its first real request

The endpoint 500'd on the live stack immediately after deploy:

```
__PHP_Incomplete_Class — App\Services\Pipeline\HealthCheck
```

`Cache::remember` was storing the check objects themselves. Redis is shared
across every container and across a deploy, so a serialised domain object
outlives the code that defined it: one version wrote it, another read it. Only
primitives go in now, and the objects are rebuilt on the way out. Two tests
cover it — one asserts the cached shape is arrays, the other feeds a
hand-written cache entry through and expects real objects back.

Worth recording as a class of bug rather than an incident: anything cached in a
shared store is part of the deployment contract, whether or not it was designed
that way.

### And a second one, quieter

`PipelineHealthWidget` came out of the first full run at **0.0% coverage** —
registered on the panel, rendered by nothing, covered by nothing. Exactly the
shape of gap that made the whole pipeline dead a week ago, in miniature. It now
has five tests that render it through Livewire, including one asserting it is
actually registered on the panel rather than merely written.

A name collision surfaced in the same run: two test files declared
`snapshotFor()`, which Pest shares in one global namespace. Fine in isolation,
fatal together, so both are now specific.

### Gates

| Gate | Result |
|---|---|
| Pest | **535 passed**, 1 skipped, 2,063 assertions, **94.4%** |
| PHPStan level 6 | 0 errors |
| Pint | clean |
| OpenAPI drift | up to date |

On the running stack the report is honest rather than flattering: everything
`ok` except `review_queue`, degraded because the demo's seeded backlog of 1,137
submissions has never been worked and its oldest entry is 183 days old. That is
the check doing its job on the first day it existed.

**Still open:** 13.4 FX ingestion, 13.6 the Playwright loop test.

---

## Phase 13.6 — the proof, on every push — complete

Four end-to-end tests against the composed stack, and a CI step that runs the
whole suite there. The headline claim stops being something demonstrated by
hand and starts being something a build fails without.

**`e2e/tests/loop.spec.ts`** walks the wire the platform spent a week without:
read the published snapshot, post a price to the public API, and wait — for the
real matcher, the real screening service, the real scheduler, the shipped
defaults — until the figure changes. It takes about ninety seconds, which is the
publish grace window plus a drain cycle, and that is the point: it measures the
cadence a reviewer would actually experience rather than a shortened one.

It names no country. The fixture is discovered from `/api/v1/countries` and
whatever basket comes back, because a proof that only works for the default
deployment proves the wrong thing.

Three more assertions worth having: that a published snapshot mentions no
reporter, no submission id and no device — the reporter is the person taking
the risk of standing in a market writing prices down, and nothing about them
belongs in a public payload; that the public health block reports the scheduler
as recently alive; and that it exposes no counts.

**CI now runs all 27 end-to-end tests** in the existing compose job, so they
ride on a stack that is already up rather than paying for a second image build.
The job is renamed to what it now checks: *One-command demo boots clean, and the
loop closes.*

Two small things caught in passing. The basket endpoint returns its payload
unwrapped, unlike the collection endpoints — the first version of the fixture
guessed `data.items[].item.code` and failed against the first real response,
which is a small argument for reading payloads rather than assuming them. And
the workflow YAML was parsed locally before pushing, because the first CI run
this project ever attempted failed on a YAML error that produced no jobs and no
annotations.

| Gate | Result |
|---|---|
| Playwright, against the composed stack | **27 passed** in 1.9 min |
| Pest | 535 passed, 1 skipped, 94.4% |
| pytest | 196 passed, 83.1% |

**Still open:** 13.4, FX ingestion — the last piece of the plan, and the one
whose hard part is sourcing rather than code.

### 536 warnings nobody could read

Adding the end-to-end step meant reading a CI log properly for the first time in
a while, which surfaced something that had been true since well before this
phase: **every test in CI emitted a PHP warning**, and the summary line said
`536 warnings` rather than `535 passed`. It failed nothing, so it had never been
noticed — and it meant a genuinely new warning would have been invisible in the
noise, which is the part that mattered.

Diagnosing it took two wrong turns worth recording:

- The message was truncated to `file_get_contents(/home/r…` because the
  reporter trims each line to the terminal width and CI has none. Fixed by
  giving the step a `COLUMNS`, which makes every future truncated message
  readable rather than just this one.
- JUnit output was added on the assumption it would carry the full message. It
  does not — these are PHP notices Pest displays per test and the JUnit logger
  does not record, so the artifact reported zero warnings. That also invalidated
  an earlier local experiment which had "ruled out" the cause using exactly that
  blind instrument. The artifact is worth keeping regardless; the conclusion
  drawn from it was not.

The cause was `file_get_contents(.../api/.env)`. The suite is deliberately
runnable without one — `phpunit.xml` carries the test key and test database so a
fresh clone works — but the framework still opens the file, and a missing one
warns once per test. CI now creates an empty `.env`: empty rather than a copy of
`.env.example`, which sets a different database and a blank `APP_KEY`, and the
values are phpunit.xml's job.

CI now reports `1 skipped, 535 passed (2,063 assertions)` with no warnings at
all, which is what a clean signal looks like.

---

## Phase 13.4 — exchange rate ingestion — complete

The last part of the plan, and the one whose answer turned out to be "no, and
here is why".

### The source question, settled

A parallel-rate source for the dinar was suggested: **fulus.ly**. Checking it
rather than assuming:

```
GET https://fulus.ly/api/v1/rates/current  →  401 {"message":"Unauthenticated."}
```

It needs an API key, which makes it a proprietary third-party API. Wiring it in
as the shipped default would breach **C1** — no proprietary or paid service in
the runtime path — and would falsify SECURITY.md's claim that a correct
deployment has no third-party keys to leak. Every deployment of this repository
would inherit an account to create and a secret to keep.

So the platform ships knowing how to read *a* JSON endpoint and nothing about
which one:

- **`manual`** is the default for every country. An operator enters rates in the
  admin panel and the health check warns before the last one goes stale enough
  to withdraw dollar figures. Not a defeat — for most of these currencies there
  is no free machine-readable parallel rate anyone would stake a figure on.
- **`generic_http`** reads whatever endpoint a country file names, with dot
  paths into the response and an optional auth header whose token is read from a
  **named environment variable** — so a country file under version control never
  contains the credential.

`countries/ly.yaml` carries the fulus.ly configuration as a commented, opt-in
example with the two things an operator must settle first: that the terms permit
automated access, and that the paths match what the endpoint actually returns.
The paths there are a guess made without a key, and are labelled as one — a
wrong path yields no rate rather than a wrong one, and the health check says so.

Nothing in `api/app` mentions the service. The C3 check would fail the build if
it did.

### D-16, made real

`FxRateResolver` broke ties by recency alone, which was harmless while one
source existed and would have meant tonight's scheduled fetch silently
overruling the correction an operator typed that afternoon after speaking to a
trader. Precedence is now `is_manual DESC, fetched_at DESC` on both the
same-day lookup and the fallback to an earlier day. Two tests hold it.

### The SSRF hole named in SECURITY.md, closed

`SECURITY.md` has listed server-side request forgery through the FX and scraper
configuration as in scope since Phase 12, and nothing enforced it: the scraper
would fetch any URL a source named, including `http://169.254.169.254`, and hand
the cloud instance's credentials back inside an ingestion batch's error report.

`OutboundUrl` refuses non-http schemes, URLs carrying credentials, and any host
resolving to a private, loopback or link-local address. Both call sites use it.
The DNS-rebinding gap is documented rather than papered over — closing it needs
the resolved address pinned into the connection, which the HTTP client does not
expose.

**A design error caught by the existing suite.** The first version treated an
unresolvable host as a refusal, which broke nine scraper tests that fake
fictitious hostnames. That was not merely a test problem: it conflated a
transient resolver failure with an attack, and would have told an operator who
typo'd a hostname that their configuration had been rejected on security
grounds. A name with no address points nowhere, so it is allowed through and
fails at connection with a message about the host.

### Gates

| Gate | Result |
|---|---|
| Pest | **557 passed**, 1 skipped, 2,101 assertions, **94.3%** |
| PHPStan level 6 | 0 errors |
| Pint | clean |
| C3 country-agnostic | pass |

Twenty-one of those tests are this phase, and six of them are refusals: a
loopback address, a private network address, the cloud metadata service, a URL
with credentials, a non-http scheme, and a rate that is not a positive number.

**The plan is complete.** All six sub-phases of Phase 13 are done.

---

## Phase 14.1 — the interval now covers what it claims

The published interval under-covered: an 80% band holding **74.6%** of true
values, so roughly one value in twenty fell outside a band advertised to contain
four in five. Not catastrophic, and the wrong direction for a platform whose
argument is honesty about uncertainty to be wrong in.

Both documented remedies were measured on the same backtest rather than argued
about. The full table is in `docs/model-cards/nowcast-evaluation.md`; the two
lines that decided it:

| Quantiles drawn | Conformal | Coverage | Width | MAPE |
|---|---|---|---|---|
| 0.1 / 0.9 | none *(was)* | 74.6% | 10.9% | 3.49% |
| **0.05 / 0.95** | **none** *(now)* | **85.6%** | **14.3%** | **3.49%** |

The band is drawn wider than it is published, so `QUANTILES` and
`NOMINAL_COVERAGE` are now separate constants and a test asserts the first spans
more than the second — the mismatch is the margin, and it would read as an
inconsistency to anyone tidying up.

**Conformalised quantile regression was built, measured and deleted.** It was
the a-priori favourite: its guarantee is distribution-free, which should matter
most for a model destined to meet markets unlike its training data. It bought
**+0.2 points** — 74.6% to 74.8% — for a quarter of the training data.

The reason is not a defect in the method. Split conformal guarantees coverage
under exchangeability, and a temporal backtest breaks exchangeability by
construction: calibration rows come from the training period, test rows are
weeks later with prices drifted by inflation and FX pass-through. Calibrating on
the most *recent* slice instead of a random one recovered part of the gap —
74.8% to 77.1% — which confirms the diagnosis and still fell short. The simpler
remedy won outright and carries no machinery.

Also fixed while in there: `predict()` hardcoded `0.1` and `0.9` while a
`QUANTILES` constant above it claimed to be authoritative, so changing the band
produced a `KeyError` at prediction time rather than a different band. The
evaluation's pinball loss was scored against the same literals.

### What this fix cannot currently reach

Verified on the running stack, and worth stating plainly because it changes how
the nowcasting model card should be read:

```
imputation_method | count
------------------+-------
fallback_local_median | 2653
```

**Every imputed value in the live index comes from the fallback heuristic. None
comes from the model.** Three findings behind that:

1. **Nothing calls `/v1/nowcast/train`.** The Laravel client implements
   `nowcast()` against `/v1/nowcast/impute` only, so the LightGBM models are
   never fitted in a running deployment and `impute` always declines to the
   heuristic. The same shape as the pipeline that had no caller, and the review
   queue that had no screen.
2. **Three of eleven features are hardcoded** in `ItemImputer`:
   `nearest_neighbour_km => 50.0`, `fx_change_30d => 1.0`,
   `location_price_level => 1.0`. Even once trained, the model would be scored
   on a feature distribution its evaluation never saw.
3. Therefore the model card's headline — 3.5% MAPE, +61% against baseline,
   and now 85.6% coverage — describes a model **no deployment has ever run**.

The interval fix is still right and will matter the moment training is wired.
But the published index today is imputed by a ±30% heuristic, and the model card
should be read as describing a component that is measured, not one that is in
service.

**Next, and not attempted here:** a training path — assembling point-in-time
features from observations and posting them to `/v1/nowcast/train` on a
schedule, plus computing the three placeholder features for real. The hard part
is not the plumbing but lookahead bias: features assembled with any knowledge
after the target date would inflate the model's apparent quality exactly the way
this project keeps finding things inflated.

### A test that only failed after dark

The closed-loop tests failed during this work, and not because of it. At
22:29 UTC the machine was in 11 August and Tripoli was already in 12 August.
`qeema:index:publish` correctly publishes for the country's calendar day; the
tests asked the API for the *server's*. Two hours of every day, they 404.

The same assumption was in `e2e/tests/loop.spec.ts`, which computed a UTC date
locally — so CI would have failed nightly for the same two hours, on a schedule
nobody would have connected to a timezone.

Both fixed by taking the date from the platform rather than computing one: the
PHP test derives it from the country's timezone, and the end-to-end spec now
reads it out of `/index/current`, which is the authoritative answer and needs no
timezone reasoning at all.

Worth noting because D-14 was written carefully to get exactly this right in the
*command*, and then the tests written against it assumed a server-local today.
The care did not transfer across the boundary on its own.

---

## Phase 14.2 — the nowcast model is now actually trained

The previous entry recorded that nothing in the platform ever called the
training endpoint, so the LightGBM models were never fitted in a deployment and
every imputed price came from a ±30% heuristic. This closes that, and turned out
to be larger than "add a command".

### The features were not the features

`ItemImputer` sent eleven named features. **Four were constants** —
`nearest_neighbour_km` at 50.0, and `national_trend`, `fx_change_30d` and
`location_price_level` at 1.0 — and two more, `national_median` and
`neighbour_median`, were computed over identical rows, because the "k nearest
neighbours" the docstring described was implemented as "every other location".

So a model evaluated on eleven signals would have been served seven, one of them
duplicated. `fx_change_30d` pinned at 1.0 is the one worth naming: it told the
model the currency never moves, in countries this platform exists for *because*
it does.

`NowcastFeatureBuilder` now computes all eleven, and both the imputer and the
trainer call it — one assembly, because two would drift and the drift would look
like the model simply being bad.

### The rule that makes training honest

**No observation of this item at this location dated on or after the target date
is ever read.** At serving time that costs nothing: the cell is unobserved,
which is why it is being imputed. At training time it is the whole game, since
the target *is* such an observation.

The guard is a test that builds the features, then adds every kind of future
evidence — a later price at the same location, a later price elsewhere, a later
exchange rate — rebuilds, and requires the result to be byte-identical. A
lookahead path anywhere shows up as a difference. Lookahead does not announce
itself; it shows up as a model that evaluates beautifully and fails in service.

`national_median` also excludes the target location entirely, so the price being
predicted cannot sit inside the number it is divided by.

### Verified on the running stack

```
before   fallback_local_median   2653      lightgbm_quantile      0
after    fallback_local_median    171      lightgbm_quantile    127
```

Trained on 3,000 rows, republished, and 43% of imputations now come from the
model. The remainder legitimately fall back — no national reference exists to
anchor a ratio against, and the model declines rather than guessing.

### A new health signal, for a failure that would otherwise be silent

The fitted model lives in the ML service's process memory, so restarting that
container unfits it and every estimate reverts to the heuristic. The figures
keep publishing, keep their intervals, stay labelled imputed — and become much
cruder than the model card describes, with nothing saying so.

`imputation` is now a pipeline health check: it samples recent imputed items and
reports degraded when none came from the model. Training runs every six hours
rather than daily for the same reason.

### Known, and not fixed here

**The ML service holds one model for all countries.** `_model` is a module-level
global, so training Libya and then Venezuela leaves only Venezuela's fit serving
both. Because targets are ratios to a national median the model is scale-free
and this is not nonsense — but it is "whichever country ran last", not a
deliberate cross-country model. Fixing it means keying models by country through
the ML API, the client and the registry.

**And the model card still describes synthetic data.** 3.5% MAPE and 85.6%
coverage are measured against a generator whose price process is known. What
changed here is that the model is now reachable at all; how well it does on real
markets remains unmeasured, and will stay so until a pilot produces real
history.

| Gate | Result |
|---|---|
| Pest | **577 passed**, 1 skipped, 2,154 assertions, 93.9% |
| pytest | 199 passed, 83.1% |
| PHPStan level 6 | 0 errors |
| Pint / ruff / mypy | clean |
| OpenAPI drift | up to date |
| C3 | pass |

---

## Phase 14.3 — one model per country

The previous entry recorded that `_model` was a single module-level global in
the ML service, so training Libya and then Venezuela left only Venezuela's fit
answering for both. Fixed.

Models are now keyed by country, and **the country is a required field on both
nowcast endpoints** rather than an optional one with a default. A default is
precisely how one country's prices end up being served from another country's
model without anyone choosing that — the same shape as every other silent
failure this project has turned up.

### Why it was invisible

Targets are ratios to a national median, so the model is scale-free by design.
A Venezuelan fit imputing Libyan items therefore returned *plausible* numbers:
a ratio multiplied by Libya's own anchor. Nothing looked wrong, no test could
fail, and the figures published. The tests that now hold the boundary assert
that two countries trained to opposite ratios return different values for
identical context, and that an untrained country still falls back rather than
quietly borrowing a neighbour's model.

### On the running stack

```
LY   lightgbm_quantile   68     fallback_local_median   61
VE   lightgbm_quantile  127     fallback_local_median    0
```

Both countries trained, each from its own history, each imputing from its own
fit. Libya's remaining fallbacks are the cells with no national reference to
anchor a ratio against, where the model declines rather than guessing.

| Gate | Result |
|---|---|
| Pest | **578 passed**, 1 skipped, 2,156 assertions, 93.9% |
| pytest | **206 passed**, 84.2% |
| PHPStan level 6 | 0 errors |
| Pint / ruff / mypy | clean |
| C3 | pass |

**Still true, and still the honest headline:** the fitted models live in the ML
service's memory and are lost on restart, which the `imputation` health check
reports and the six-hourly retraining repairs. And every number in the
nowcasting model card is still measured against synthetic data. The model is now
reachable, per-country, and trained on point-in-time features — how well it does
on a real market remains unmeasured.

---

## Phase 14.4 — photographs stop carrying the photographer

A reporter photographs a price tag in a market. Their phone writes the
coordinates, the timestamp to the second and often a device identifier into the
file. Attached to a submission that already names the location, that is a record
of where a particular person stood on a particular afternoon — and in the
economies this platform exists for, the people it would expose are not the ones
who would think to check.

`SECURITY.md` had listed "strip EXIF on ingest" as an **operator
responsibility** since Phase 12. An operator who forgets has no way to discover
the omission. It is now the platform's job, done before the file reaches disk,
and the operator cannot forget.

### Lossless, not re-encoded

Neither GD nor Imagick is in the runtime image, and adding one to decode and
re-encode would have solved it — while softening the photograph, which is the
one thing a reviewer needs, because they are reading small print off a price
tag.

Both formats are containers of discrete segments, so the metadata is lifted out
and the compressed picture data passed through byte for byte: JPEG APP1 through
APP15 (EXIF, XMP, ICC, IPTC) and COM, and the PNG `eXIf`, `tEXt`, `iTXt`,
`zTXt` and `tIME` chunks. APP0/JFIF is kept — it describes pixel density, not a
person.

Three deliberate refusals:

- **A format it cannot clean is not stored.** Returning the original would be
  the quiet failure the class exists to prevent: a file whose metadata survived,
  written as though it had not.
- **A malformed file is not guessed at**, for the same reason.
- **The submission is still accepted** when the photograph is dropped. The price
  is the contribution and the picture is corroboration; a reporter on a bad
  connection has already spent their data sending it.

Uploads are now restricted to JPEG and PNG rather than Laravel's `image` rule,
which also admits SVG — a document that can carry script and has no picture data
to separate metadata from.

### What this does not fix, stated in the policy

Stripping metadata does nothing about a face, a shopfront or a licence plate in
the frame. Retention and access remain an operator decision, and reporter
photographs stay behind the admin login. `SECURITY.md` now says that rather than
implying the problem is solved.

The test builds a JPEG byte by byte with a GPS payload in an APP1 segment and a
name in a comment, posts it through the public API, reads the file back off the
disk, and requires the payload to be absent and the picture data present. The
fixture is constructed rather than committed so that what is being stripped is
readable in the diff.

| Gate | Result |
|---|---|
| Pest | **588 passed**, 1 skipped, 2,179 assertions, 93.8% |
| PHPStan level 6 | 0 errors |
| Pint | clean |
| OpenAPI drift | up to date |
| C3 | pass |
