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

---

## Phase 14.5 — fitted models survive a restart

The models lived in the ML service's process memory. A restart — a deploy, a
crash, `docker compose up -d` — dropped every country back to a ±30% fallback
heuristic until the next scheduled training run, up to six hours later, with
figures that kept publishing and were quietly much cruder than the model card
describes. They are now written to a volume and read back at startup.

### The manifest is the point, not the model files

Persisting introduces a failure mode that not persisting does not have: a model
fitted on one set of features, loaded by code that now sends a different set.
Features are positional, so **nothing raises** — the model reads one feature out
of another's slot and returns a number indistinguishable from any other.

Every saved model therefore carries the ordered feature names, the quantiles it
was fitted at, and the coverage those were meant to deliver. A model whose
manifest disagrees with the running code is refused, logged, and left on disk so
an operator can see what was rejected rather than wonder where it went. Six of
the twelve tests are refusals, including a model relabelled to the old 0.1/0.9
quantiles — loading that would silently reinstate the interval that covered
74.6% of what it claimed.

Boosters are saved in LightGBM's own text format rather than pickled: a pickled
estimator does not survive a library upgrade, and this file has to be readable
by whatever version is running six months from now.

### Caught by checking rather than by trusting

Training reported success on the live stack and **nothing was written**. The
volume had been created before the image had a `/models` directory, so it was
owned by root while the service runs as uid 10001 — and `save()` is deliberately
non-raising, because a training run must not fail over a read-only disk. The
whole failure was one log line:

```
Could not persist the LY nowcast model: [Errno 13] Permission denied: '/models/LY'
```

The image now creates the directory owned by the service user, so a volume
initialised against it inherits that ownership and a clean deployment is correct
without intervention.

Verified the only way that means anything — trained, then `docker compose
restart ml`, then asked the service:

```json
{"method": "lightgbm_quantile", "model_trained": true}
```

Before this, that same request immediately after a restart returned a fallback.

### Two smaller things, while in there

`nowcast_quantiles`, `nowcast_neighbours` and `artifact_dir` were settings read
by nothing. The first also **lied**: it advertised 0.1/0.9 while the model has
shipped 0.05/0.95 since the interval was recalibrated, so an operator "tuning"
it would have changed nothing at all. Removed, with a note in its place
explaining that the band is a calibration decision measured against a backtest
rather than an operator knob.

The runbook's `imputation` section previously blamed container restarts. That is
no longer the cause, so it now names the three real ones — never trained, a
refused model, or training failing — and says how to tell them apart.

| Gate | Result |
|---|---|
| pytest | **218 passed**, 85.0% |
| ruff / mypy | clean |
| C3 | pass |

---

## Phase 14.6 — one flaky test fixed, one speculative fix backed out

CI reported two flaky end-to-end tests in the reporter's offline path: one
failed outright, one passed on retry. A suite that cries wolf is one people stop
reading, and a real regression in the offline path could hide behind a shrug.

### The one that was genuinely racing

`replaying the queue does not send the same price twice` enters a price offline,
reads the queued payload out of IndexedDB, restores the network, and posts that
payload twice to prove the server treats the replay as a duplicate.

The problem is the third sender. Restoring the network fires the app's own
`online` handler, which flushes the outbox and posts **that very payload**. When
the app wins the race, the test's "first" send comes back `200 duplicate`
instead of `201 accepted` and the assertion fails. The flake was in the test's
assumption that it was the only sender, not in the platform.

The item is now removed from the outbox before the network is restored, so the
app has nothing to send and the test measures what it claims to: the server's
handling of a replay.

### The one I got wrong

The other test, `a price entered offline is kept and sent on reconnect`, had no
proven cause. Reading the queue implementation turned up what looked like one:
`flush()` has no re-entrancy guard, five separate things call `sync()`, and
`pending()` deliberately includes items already marked `syncing` — so two
concurrent flushes would post the same item twice and write its status from
stale copies.

I added a guard that collapsed concurrent callers onto the in-flight flush.
**It broke a different test**, and measuring showed why rather than leaving it
to argument:

| | `several offline entries all survive and sync together` |
|---|---|
| Without the guard | 24/24 passed, 17s |
| With the guard | 2 of 3 repeats failed — 1 of 3 items synced |

Collapsing is the wrong shape. A caller that arrives while a flush is running
gets that flush's promise, and that flush already read its work list — so items
enqueued afterwards are never sent and nothing re-triggers. Precisely the
"Received: 1" the failure reported.

Serialising rather than collapsing would fix that. I have not shipped it,
because the concurrency it guards against is **still unproven**: two overlapping
flushes are possible by construction but I never demonstrated one occurring, and
I have just watched an unproven fix cause a real regression. The observation is
recorded here instead of being acted on speculatively.

So the application code is unchanged and only the test moved. Locally the suite
now passes 27/27, and the offline file passes 16/16 across repeated runs.

### What is still open

The first test's flakiness under CI load remains **undiagnosed**. Two hypotheses
were tested and both were wrong: `navigator.onLine` flips reliably after
`setOffline(false)` (8/8 in a probe), and no request was rate limited (zero 429s
across a full repeated run). It may simply be slower under a contended runner
than its 20-second poll allows. If it recurs, the next step is the trace CI
already uploads on failure, rather than another guess.

---

## Phase 14.7 — somebody is now looking for manipulation

`reporter_bias` has existed since Phase 6, is covered by its own tests, and had
**no caller in either service**. The platform's only defence against coordinated
price manipulation was a module nothing ran — while the synthetic generator has
been seeding a bad-actor cluster into the demo data since Phase 2, and nothing
had ever looked for it. `is_blocked` was likewise only ever set by a hand-toggle
in the admin: `RecordSubmission` enforces it, so the gate worked; nothing fed it.

Now wired: an endpoint on the ML service, a daily command that assembles the
evidence, a column recording what was found, and the finding shown against the
reporter in the admin panel.

### The property the whole thing rests on

**The reference excludes the reporter being judged.** A cluster large enough to
shift a local median otherwise hides inside it: measured against a median it
helped set, a coordinated group looks unremarkable. The command computes, per
observation, the median for that item in that place across *other reporters*,
and a test asserts the suspect's own price is nowhere in the number they are
judged against.

A reporter who is simply the only one working a place produces no record at all.
Judging them against themselves would flag whoever works alone in a remote town
— exactly the places this platform exists for.

### Measured against the manipulators actually planted in the data

Not "it flagged some people". Run end to end on the demo stack and checked
against `qeema_eval`, which knows who was manipulating:

| | reporters | actually manipulating |
|---|---|---|
| Flagged | 9 | 6 |
| Not flagged | 119 | 2 |

**Recall 6/8. Precision 6/9.** It catches three-quarters of them, and one flag
in three is a person doing nothing wrong.

### Which is exactly why it blocks nobody

That precision figure is the argument. An automatic rule acting on this signal
would have silenced three honest reporters — people doing real work in a
difficult place — to catch six manipulators. So the detector records a score, a
reason in words, and the date it looked; a human decides what follows, the same
way an unconfident match goes to a review queue rather than being guessed at.

A reporter somebody has already cleared is not raised again unless their
behaviour changes, so the queue stays small enough to be worth reading. A test
holds each of those properties, including one that simply asserts `is_blocked`
is still false after the most extreme possible finding.

### Gates

| Gate | Result |
|---|---|
| Pest | **598 passed**, 1 skipped, 2,209 assertions, 93.7% |
| pytest | **224 passed** |
| PHPStan level 6 | 0 errors |
| Pint / ruff / mypy | clean |
| C3 | pass |

---

## Phase 14.8 — a systematic sweep, and the fifth unreachable component

Four components have been found built-and-unreachable by accident this week —
the resolution pipeline, the review queue, nowcast training, and the
manipulation detector. Four is not bad luck, so this time the codebase was
searched deliberately: every class under `app/Services`, `app/Support`,
`app/Actions` and `app/Jobs`, and every ML module, checked for references
outside its own file.

The first attempt produced a list of about fifty "unreachable" classes,
including `RecordSubmission`, which is called on every submission. An unquoted
`--include=*.php` had failed in zsh, so every grep matched nothing and the sweep
reported everything as dead. A sweep whose answer is "all of it" is not a
finding, it is a broken tool — worth recording because it took a second look to
notice rather than a first.

Quoted properly, the sweep returned four results, three of them expected:
`FakeMlClient` is a test double, and the two OpenApi classes hold attributes
that swagger-php scans rather than code anything calls.

### The fifth: nothing ever ran a scraper

`ScraperRunner` was referenced only by its own tests. Everything beneath it
worked — pagination, rate limiting, robots.txt, resumable cursors, deterministic
idempotency keys, all covered — and nothing ran any of it. A source of type
`scraper` configured in the admin panel sat there and was never fetched.

`qeema:scrape` now runs the active scraper sources daily.

**A stock deployment fetches nothing.** The platform ships with no scraper
source configured, so the scheduled task does nothing at all until an operator
sets one up — which is what makes having it on a schedule safe, and the first
test asserts exactly that with `Http::assertNothingSent()`. The demo seeds only
reporter and partner-upload sources, so `docker compose up` still reaches no
third party (C1).

What it fetches becomes ordinary pending submissions, resolved and screened by
the pipeline that already exists. There is deliberately no second path into the
index.

| Gate | Result |
|---|---|
| Pest | **606 passed**, 1 skipped, 2,228 assertions, 93.7% |
| PHPStan level 6 | 0 errors |
| Pint | clean |
| C3 | pass |

---

## Phase 14.9 — an estimate nobody has tested counts for less

Two changes, one to CI and one to the estimator.

### The flake was undiagnosable by construction

CI uploaded the Playwright report `if: failure()`. A *flaky* test fails and then
passes on retry, so the job succeeds and the trace of the failure was discarded
— which is why the one flaky test here has survived several occurrences with no
evidence to look at. Now uploaded on `always()`, along with `test-results`.
Traces are only written when something failed, so a clean run uploads nothing.

### Weighting by what is known, not just what is believed

The estimator weighted each observation by its reporter's reputation: the mean
of a Beta posterior, floored at 0.25 so a reporter who was wrong early can climb
back.

The mean cannot distinguish two very different reporters. One has submitted
nothing and sits at 0.5 because that is where the uninformative prior puts them.
The other has a hundred accepted and a hundred rejected and sits at 0.5 because
that is genuinely what they are worth. Their prices counted the same.

It also made identity rotation profitable. Identity here is a UUID the client
generates — deliberately, because requiring a signup would suppress the
participation the platform runs on — so a reporter whose submissions keep being
rejected can discard it and start again. Under the mean, that reset took them
from the 0.25 floor back to 0.5: **doubling their weight**.

The weight is now the posterior's **lower bound** — one standard deviation below
the mean, a plain choice because it has to be explainable to somebody deciding
whether to trust a published figure. Measured on the running stack, a fresh
identity records:

```
reputation 0.5000    weight 0.2764
```

Barely more than the floor it was trying to escape. A long honest record is
almost unaffected, because the spread is small when a lot is known.

This does not solve identity. A patient attacker with many identifiers and
plausible prices is still not individually detectable, and closing that needs a
decision about onboarding friction that belongs to whoever runs the pilot. It
removes most of what rotating an identity was worth.

### No published figure changed

`weight_at_time` is frozen onto the observation, beside `reputation_at_time`
rather than replacing it — reputation is a statement about a person, shown in
the admin and fed to the anomaly detector as a feature, while the weight is what
the estimator did with their price. Both belong on the provenance record.

All 21,393 existing observations carry a null weight and fall back to the old
behaviour. Recomputing March's snapshot must reproduce March's figure rather
than restate it under rules introduced in August.

**A rebuild caught in the act:** the first live test showed no weight recorded,
because only the `app` image had been rebuilt and resolution runs in the
`worker`.

| Gate | Result |
|---|---|
| Pest | **609 passed**, 1 skipped, 2,233 assertions, 93.6% |
| PHPStan level 6 | 0 errors |
| Pint | clean |

### An overclaim in the honesty document, caught immediately

The first draft of `docs/assessment.md` said its figures "are re-checked by CI on
every push, so a stale one shows up as a failing build". That is not true. CI
enforces that the suites pass and the coverage floors hold; nothing compares the
numbers written in that file against reality, so a stale figure would sit there
indefinitely looking authoritative.

Writing an unchecked claim about checking, in the document whose entire purpose
is to separate the verified from the assumed, is the exact failure the document
exists to prevent. It now says the figures are a snapshot with a date and how to
regenerate them.

`AssessmentDocsTest` holds the parts that *can* rot silently — every linked
document exists, every `make` target and artisan command it names is real — and
one test asserting the sentence about what its numbers are worth is still there,
because that sentence is what stops a reader taking a stale figure for a current
one.

### Two commits where CI ran nothing at all

The `if: always()` change to the Playwright artifact step left `if-no-files-found`
in the mapping twice. GitHub Actions rejects a duplicate key outright, so both
that commit and the next one ran **zero jobs** — no suites, no coverage gates, no
compliance check. The runs are recorded as failures, but nothing in them had been
tested and failed; nothing had been tested.

That is worse than a red build. A red build tells you what broke. This tells you
only that the thing which tells you is broken, and it does so in the same place
you would normally look for reassurance.

It survived a YAML validation I ran at the time, because Ruby's Psych, PyYAML and
js-yaml all accept a duplicate key and keep the last value. Validating the file
loads proves nothing about whether Actions will take it.

`infra/scripts/check-workflows.sh` walks the parse tree instead of trusting the
load, and is in `make verify` rather than in CI — a check for "the workflow is
invalid" cannot live inside the workflow it is checking, because an invalid one
never starts it. Verified by reintroducing the exact duplicate and watching it
name the line.

## Phase 15 — the index survives a basket revision

`cost_local` is the cost of one specific basket. Revise the basket — an item
stops being sold, a new one becomes essential, a weight stops reflecting what
households spend — and the series steps for a reason that has nothing to do with
prices. Anyone plotting it reads that step as inflation. It was the last
methodological gap listed in `docs/assessment.md`, and the one a statistician
would find first.

### What was already there, and what it was doing

More was in place than expected, and none of it was connected:

- `baskets.version`, `effective_from`, `effective_to`, `isEffectiveOn()` — built
  and used; `Country::basketOn($date)` already picked the right basket per date
- `index_snapshots.normalized_index` — a column with a Filament table column, an
  infolist entry and a form field bound to it, which **nothing had ever written**
- `index.base_date` in every country file — parsed, validated, and **read by
  nothing**
- three items in `ly.yaml` catalogued but deliberately left out of basket v1,
  commented as being there to exercise "the basket-versioning and chain-linking
  path"

So the shape had been designed and the mechanism never built. The factory filled
`normalized_index` with `100.0`, which is why no test ever noticed: every test
saw a plausible number and every deployment had null.

That is the sixth component found built-but-unreachable, and the pattern is now
consistent enough to be worth naming — a column with UI attached and no writer
looks exactly like a working feature from every angle except the database.

### The construction

Every basket version gets a per-location reference cost, after which the level is
uniform across versions and nothing downstream needs to know a link happened:

```
level(L, t) = 100 × cost_v(L, t) / reference_cost_v(L)
```

The first version is anchored at the base period. Each later version is carried
forward by costing **both** baskets on the last day the old one was in force —
the only day they are directly comparable — and multiplying by the ratio:

```
link_factor(L)   = cost_new(L, d) / cost_old(L, d)
reference_new(L) = reference_old(L) × link_factor(L)
```

which makes the level continuous at `d` by construction.

Decisions are recorded as D-19…D-23 in `docs/plan-chain-linking.md`: link per
location with a country-median fallback; never anchor on a basket that could not
be fully priced; anchors immutable once written; costing must not persist; and
`normalized_index` renamed to `index_level`, which is safe precisely because
nothing had ever written it.

### The test that matters

Every price is held constant across the revision. Under those conditions the
correct index does not move at all while the cost must jump, so the two
assertions together prove the link is working rather than the numbers merely
looking plausible. Values are hand-computed: v1 = 18, v2 = 24, factor = 4/3,
level = 100 on both sides.

Verified by mutation — removing the link factor makes the level jump to
**133.3333**, exactly 24/18 × 100, which is the artefact being removed.

### Three more dead seams found on the way

Trying to run this against the live demo turned up more of the same:

**`index_config` and `fx_config` were never imported.** Every country file
carries an `index:` and an `fx:` block; both are parsed and validated; the
importer wrote neither. Both columns were null on every deployment, so every
setting in them was decorative and the code silently used its own defaults. An
operator widening `observation_window_days` for their country would have seen no
effect whatsoever. This also made chain-linking impossible, since `base_date`
lives there.

**There was no way to apply a country-file edit.** `qeema:bootstrap` seeds a
country only when it is absent — a deliberate guard to keep restarts cheap — so
editing `countries/*.yaml` on a running deployment printed "already seeded;
skipping" and changed nothing. The importer underneath has always been idempotent
and says so in its own docblock; nothing exposed it. `qeema:config:import` now
does, which matters most for basket revisions, since that is how one is
expressed: without it the feature could not be reached by anyone following the
runbook.

**A basket revision left both versions in force.** A country file describes one
basket, so nothing in it can say when the *previous* version ended. The importer
now closes an open earlier version the day before the new one starts, and leaves
alone any version whose end date was set deliberately.

### The base period is measured, not asserted

`base_date: 2026-01-01` in the shipped files was outside the demo's own data,
which runs from roughly six months ago to today and rolls forward — so any fixed
date drifts out of range and every location ends up unanchored.

A configured base date is now honoured exactly and reports loudly when there is
no data there, because it is an operator's assertion about when their series
starts and quietly anchoring elsewhere would publish a series whose 100 is not
the date they documented. With none configured, the base period becomes the first
day the basket could be priced in full — a fact about the data — recorded on the
anchor and immutable from then on. The shipped files now leave it unset, with the
reasoning written where an operator will read it.

`base_period` was dropped from the API response rather than published from the
country config, because after this change the config value and the effective base
period can differ, and a field that is right only sometimes is worse than one
that is absent.

### A fourth defect, found only by running it

Every index endpoint had been written assuming one snapshot per location per
date. A revision makes that false — snapshots under both versions exist for the
dates around the changeover — and `firstOrFail()` with no ordering returned
whichever row was written first, which is the *older* basket. Live, the API
served basket v1 for a date basket v2 governed, and a history series would have
repeated each date once per version and plotted as a step that is not in the
data.

Fixed in all four places (`current`, `history`, `show`, `export.csv`) by ordering
on basket version and taking the highest. The bulk export now also carries
`index_level` and `basket_version`, since anyone computing inflation from that
file needs the comparable series rather than the cost.

This is the second time this session that a defect survived a green suite and
appeared within minutes of running the thing against real data. Both were
absences rather than errors — a column nobody wrote, a row nobody expected to
exist twice — and neither is the kind of thing a test written from the same
assumptions would catch.

### What the live run proved

A staged revision against the demo's 21,395 observations, since reverted:

- the importer closed v1 on 2026-08-09, the day before v2 began, leaving exactly
  one basket in force on every date
- the linker chained all 16 locations, country factor 1.0894, each with its own
  per-location factor recorded alongside what both baskets cost that day
- at the link date the cost differed by **2.5629%** and the level by
  **0.00000005%** — continuity is by construction, not by tuning
- anchoring marked the already-published snapshots stale, and the recompute task
  that runs every minute filled in their levels without a republish

Anchors landed at different base periods per location — most at 2026-02-09, Derna
at 2026-02-16 — because that is the first day each could price the whole basket.
That is the base-period policy working as intended rather than a defect.

Re-costing the same day twice moved the total by ~0.02%, because items that were
imputed are re-estimated on each call. The anchor is frozen, so published levels
do not drift; but it does mean a link factor measured on a day with imputed items
carries that estimate's error permanently. Recorded in `docs/assessment.md` under
what is not built, since choosing a well-observed link date is left to the
operator rather than enforced.

### The level is now asserted on a fresh install

CI going green on the chain-linking commit did not actually prove chain-linking
works on a new deployment. The compose job boots the whole stack from scratch,
but nothing in the browser suite looked at `index.level` — so if anchoring
stopped happening during bootstrap, the API would keep answering, the dashboard
would keep rendering, every gate would stay green, and every published figure
would quietly lose the one number that survives a basket revision.

That is the same failure this phase existed to remove, one level up: a feature
reachable in principle and unwatched in practice. `public-surface.spec.ts` now
asserts a non-null, finite, positive level for every location in a country's
current index, with a failure message naming the command that was missed.

Verified by mutation rather than assumed: with `index_level` nulled across all
992 snapshots the test fails, and passes again once they are recomputed.

A fresh `qeema:bootstrap --force --fresh` was run end to end to confirm the
ordering holds on a first install — anchors before computation, both countries:

```
Anchoring baskets for LY...  16 location(s) anchored via base_period.
Computing the index for LY...  Computed 496 snapshot(s).
Anchoring baskets for VE...  16 location(s) anchored via base_period.
Computing the index for VE...  Computed 496 snapshot(s).
```

992 of 992 snapshots carried a level afterwards. Draining those same 992 through
the stale queue also confirmed the route `ChainLinker` relies on: marking a
snapshot for recomputation does lead to a level appearing, without a republish.

## Phase 16 — pilot readiness

### The exchange rate question, answered against C1

`fulus.ly` was raised as a parallel-rate source for Libya. Its own OpenAPI spec
settles whether it can be one: every endpoint is `bearerAuth`, `403 No active
subscription` is a documented global error, and the tiers are Free (USD only),
Basic and Pro. A keyed, subscription-gated commercial service in the runtime path
is exactly what C1 forbids, so Qeema cannot depend on it — and it is operated by
Binary Tech Ltd, who also wrote this platform, which is a second reason to be
careful rather than casual about how it appears here.

The platform already had the right shape for this and `assessment.md` already
said so: it reads any JSON endpoint an operator configures and ships with none
configured. So `deployment.md` now carries it as a **worked example** — labelled
as commercial, keyed, third-party, with the authorship relationship disclosed in
the same paragraph — while manual entry stays the default and nothing depends on
it. No code was needed; the existing `GenericHttpFxProvider` consumes it as-is.

Three tests hold the documented configuration to the response shape the service
publishes, because a configuration that is documented but does not parse is worse
than none — it is followed confidently. They assert the dot paths resolve, that
the token is sent verbatim (so a bearer scheme lives in the environment variable,
which is what the document tells an operator to do), and that a 403 withholds a
rate rather than inventing one. No network call and no key: the point is that the
configuration is right, not that the service is up.

### The Arabic surface

Reviewed rather than assumed. Key parity is exact across `en`, `ar` and `es` —
24 reporter strings and 51 dashboard strings, none missing, none orphaned. Text
direction is derived from the language subtag rather than a per-country flag, so
the locale toggle flips it correctly, and Libya defaults to `ar`.

One genuine defect. Counts are interpolated in the browser
(`.replace(':count', …)`), so `trans_choice` never runs and **the app has no
plural mechanism in any language** — English hides this behind `price(s)`. Two of
the four count strings attach a counted noun, which Arabic inflects by count:
`تم إرسال 3 سعر` should be `أسعار`, and `3 إدخال يحتاج` should be `إدخالات
تحتاج`. The other two were written to avoid attaching a noun to the number, which
is the robust strategy and appears deliberate.

Not fixed unilaterally: the repair is a wording decision in a language whose
register — Modern Standard against Libyan colloquial — is the project owner's
call, not a translator's.

### A pilot runbook

`docs/pilot.md`. Deployment covers installing it and operations covers keeping it
running; neither covers the first weeks with real reporters, which is where the
project's largest gap actually closes.

The section that matters most is the one on turning a pilot into evidence. Every
ML figure the project claims was measured against a simulation, and a pilot
replaces those numbers only if somebody deliberately captures the ground truth:
review-queue decisions are a labelled matching test set; flagged prices that turn
out genuine are real anomaly precision; a location held out of training for a
fortnight is a real nowcast backtest. It also says plainly that the manipulation
figures cannot be validated by a pilot unless somebody actually attempts
manipulation, and must not be claimed otherwise.

### The demo can now demonstrate the revision its own config promises

`ly.yaml` catalogues three items outside basket v1 and said they were there so a
v2 basket could exercise "the basket-versioning and chain-linking path". That was
not true. The generator priced basket members only, so those items had **zero**
observations — a v2 basket containing them could never be priced in full, the
linker would rightly refuse to anchor it, and the sentence described an intention
rather than a behaviour.

The generator now prices every catalogued item. The index sums basket members
only, so no published figure changes; what changes is what a revision can be
demonstrated against. Reference prices were added for the three, or they would
have defaulted to 1.0 and produced nonsense.

Verified by doing it. On a fresh reseed the three items carried 755, 700 and 688
observations, and a v2 basket adding all three chain-linked cleanly:

```
LY: 18 basket item(s).
1 | 2026-01-01 | 2026-08-09 | 15 items
2 | 2026-08-10 |            | 18 items
LY basket v2: 16 location(s) anchored via chained (country factor 1.0275).
```

Level under v1 and level under v2 agree to four decimal places at every one of
the 16 locations. The comment in `ly.yaml` now describes what the code does.

### Why the demo index contains prices the detector flagged

Chasing an implausible figure found something worth writing down. One location
showed a weekly basket cost of 10,610 against ~3,150 either side of it — a level
of 403 where every other location sat near 120.

It was not a chain-linking artefact and not a modelling failure. It was a planted
`decimal_slip` — eggs at 276 against a reference of 24 — carrying an anomaly
score of `suspect`, which **deliberately leaves the observation valid** and asks a
human to look, because discarding on suspicion alone would silently drop the
genuine supply shocks this platform exists to measure.

On the shipped demo data the detector flags 837 of roughly 1,110 planted errors.
Every one of them then waits for a reviewer who does not exist in a demo, so the
demo's own published index contains known-bad prices and can show sharp one-week
spikes. That is the review queue earning its place rather than the model failing,
and it is now written down in `operations.md` so it is not mistaken for the
latter by somebody evaluating the platform.

## Phase 17 — the reporter runs under a strict policy, and the flaky test is explained

### `unsafe-eval` is gone from every route the public touches

Alpine compiles `x-` expressions with `new Function()`, so a strict `script-src`
does not degrade an Alpine app — it stops it starting. The reporter now runs on
Alpine's CSP build, which evaluates nothing: a template may name a property or a
method and nothing else.

That meant moving every derived value out of the markup and into the component —
eleven expressions, including a ternary on connectivity, two `.replace()` calls
for counts, `||` fallbacks for localised names, an object-literal `:class`, and
`selectItem(item)` inside an `x-for`, which CSP forbids because event bindings are
method references rather than calls. The item now travels to the handler as a
data attribute. Configuration moved from an `x-data` argument — itself a call the
CSP build cannot make — into a `<script type="application/json">` block, which is
inert data rather than script and so needs no widening of the policy.

The result is better placed as well as safer: the reasoning now sits next to the
state it depends on rather than spread across markup, and it is reachable from a
test.

`/report` and `/offline` now serve `script-src 'self'`, verified live. What is
left is the admin panel and Horizon — Filament and its dependencies rather than
code written here, both behind authentication. The security test now walks the
reporter routes alongside the public ones and fails if any permits `eval`.

### The flaky test was the test, and it is fixed

It reproduced locally at roughly two runs in three, which is the first time it
has been reproducible at all — for weeks it had only ever appeared under CI load.

The setup waited for `registration.active` and then reloaded. Both steps were
wrong together. `registration.active` is already set while a worker is still in
`activating`, so the wait could pass early; the reload then raced the
`clients.claim()` it had not waited for, and the following wait for
`navigator.serviceWorker.controller` timed out at 20 seconds.

The reload was also unnecessary. Its comment said a worker "does not control the
page that registered it", which stopped being true the moment the worker started
claiming clients on activate — the comment described the default behaviour rather
than this worker's. Waiting directly for the worker to be *controlling* is both
the condition every test actually depends on and free of the race.

Eleven consecutive clean runs afterwards, and the file went from 28–32 seconds to
6–9, because most of that time had been the racing wait. The full browser suite
is 28 passed with nothing flaky.

Diagnosed first, twice: before touching the test I confirmed the reporter itself
was sound under the CSP build — no console errors, Alpine loaded, fifteen items
rendering, worker controlling — because a genuine regression and an old flake
look identical in a failure list, and I had just changed that exact file.

## Phase 18 — a harder corpus, and the first measurements above 21,000 rows

Twenty agents authored a reporter-text corpus; the deterministic generator turns
it into millions of rows. The split matters: generating text at run time would
put a hosted model in the runtime path, which C1 forbids and which would stop
`docker compose up` working on a clean machine. So the corpus is committed data
under `countries/corpus/`, and nothing at run time calls anything.

### What it is for

Not validation. Nothing here converts a figure measured against a simulation into
a figure measured against a market, and `docs/assessment.md` was not touched. The
corpus is model-authored, so its realism is asserted rather than measured.

It answers what the demo cannot. **Scale**: nothing had tested this platform above
~21,000 rows. And **text the matcher was not tuned against** — the subtle one.
`RawTextGenerator` mutates catalogue names by reintroducing hamza, switching to
Arabic-Indic digits and inserting typos, which are precisely the transformations
the matcher's normaliser undoes. Both were written from one list. A score measured
against that text is substantially a measure of whether the normaliser was
implemented correctly.

Measured rather than argued: every Libyan phrasing was passed through the
platform's own `TextNormalizer` and compared by trigram similarity against every
known variant of the correct item. **85.2% score below 0.2** — unreachable by
lexical matching at all — while 10.7% are exact matches to a catalogued variant.
The distribution is bimodal: already known, or nowhere near. That is the property
that makes it a harder test, and it is a fact about the data.

### The demo is untouched

The corpus is used only when a caller passes one. `qeema:bootstrap` produces
exactly what it always did, and the synthetic suite still passes with the same
assertion count.

### What the adversarial review found

Two review agents were given the corpus. One of them, handed an empty sample
through a mistake in the workflow arguments, **refused to review rather than
invent findings** — the correct response, and worth recording.

The substantive finding was structural rather than a list of bad rows: the codes
are category labels but the phrasings under them are SKU phrasings spanning very
different price points. A new gas cylinder against a refill. Chicken breast
fillet against whole chicken per kilo. A 10 kg crate against a kilo. A matcher
scoring well on those has learned to collapse exactly the distinctions a price
monitor exists to preserve — the corpus would reward the failure mode rather than
catch it. Twenty-one Libyan and fourteen Venezuelan entries naming a plainly
different product were deleted; the reviews also caught duplicate locations under
different slugs, region names that would not group, and one classist phrasing.

The review also caught a flaw in the method: the 80-line sample drawn for it was
not stratified, holding ten notebook lines and no chicken entries at all — which
is exactly where the densest cluster of mislabels was. The reviewer read the
whole file instead and said so.

What could not be fixed by deleting is recorded in the corpus files themselves
and in `docs/scale-testing.md`: pack and carton wordings still sit against
single-unit codes because there is no code to move them to; the corpus is
recall-only, so precision cannot be measured from it at all; the distribution is
flat where real traffic is Zipfian; there is no Arabizi; and no native speaker
has read it.

### The numbers

A complete run of 42 locations x 18 items x 1,095 days produced 1,281,120
submissions, 1,217,159 observations and 827,820 ground-truth rows in 9 min 51 s,
about 5,700 rows a second. A larger run was interrupted at 101 locations and
covers 721 of its days, which makes it useless for anything time-dependent and
fine for measuring queries against a large table: 1,425,760 observations,
1.9 GB, a 75,110-row review queue.

Against that: index computation takes **26.2 s for one day across 117 locations**,
roughly 224 ms per snapshot, dominated by the 500-draw bootstrap interval. A full
year of backfill would be about 2.6 hours — fine nightly, far too slow in front
of a user. `index/current` across 117 locations answers in 322 ms.

Those are the first performance figures this platform has ever had.

### A test that could only fail on Linux

`it ships corpora whose item codes all exist in their country file` derived an
uppercase country code from the corpus filename and then read
`countries/LY.yaml`. The file on disk is `ly.yaml`.

It passed locally on every run, because macOS resolves that path
case-insensitively, and failed the moment CI ran it on Linux. The local suite was
not wrong about the code; it was wrong about the filesystem.

Worth recording because it is the one class of failure a green local run cannot
rule out, and the fix is not "remember to be careful" — it is to use the name as
it exists on disk rather than reconstructing it, which the test now does, and to
assert the file is there so the next such mistake fails with a sentence rather
than an ErrorException.

## Phase 19 — the corpus answers back

The first corpus could only measure recall. Every line was a labelled positive,
the distribution was flat where real traffic is Zipfian, and a whole register of
Libyan typing was missing. Five agents addressed all three.

### Precision is now measurable at all

`countries/corpus/*.json` carries **distractors** — 136 for Libya, 105 for
Venezuela — wordings that match no catalogue item. Tagged by kind: another
product entirely, a fragment too vague to resolve, a greeting or test message
typed into the wrong box, and `near_miss`, which is the valuable one.

The near-misses are pointed. Semolina against wheat flour. Chicken breast against
whole chicken. Augmentin against amoxicillin. Adult Panadol tablets against
children's suspension. Quail eggs against hen eggs. A 43 kg cylinder against the
10 kg one. Those first three had been filed as *positives* in the previous
corpus and were deleted as mislabels after the adversarial review; they now
appear as things that should be refused, which is where they always belonged.

The generator emits them as submissions whose ground-truth row carries a **null
item** — the record that no catalogue entry would have been right — with no
resolution at all, because nothing matched, which is a different state from
having matched badly. A test asserts no distractor is also a catalogue wording,
since such a row would score as a false positive when matching it was correct.

### The distribution is no longer flat, and Arabizi exists

Each item declares a head: the three to six wordings that would dominate real
traffic, most common first, verified to be wordings the item actually has. The
generator samples by weight, so the head carries forty times the likelihood of
the tail rather than the same.

180 franco-arabe wordings were added for Libya — `9arora gaz`, `garoura ghaz`,
`dabba ma` — with the inconsistent digit substitution real people use. A large
share of Libyans type this way and none of it was represented.

### Two mistakes worth recording

**The distractor knob could not express a realistic share.** It was written as a
probability per location-day, which caps unmatchable submissions at one per
location per day — under 2% of traffic even at certainty, against the several
percent a public inbox actually carries. Caught by working out what the number
would produce rather than by running it. It is now an expected count, so 2.0
means about two a day per location and the share lands near 3%.

**Weighting cost a seventeen-fold slowdown.** `weightedPhrasingsFor()` rebuilt a
forty-element list of pairs on every generated submission — hundreds of millions
of allocations across a large run. Generation fell from about 2,000 rows a second
to 118, which is the only reason it was noticed: a run that should have taken
half an hour was tracking to most of a day. Memoised per item, throughput went to
**8,210 rows a second**, better than before the weighting existed.

The second one is the more instructive. It was invisible in every test — the
suite generates tens of rows, where the cost is nothing — and only appeared at a
scale the tests never reach. Which is the argument for having a scale dataset at
all.

### The run, completed

99 locations, 18 items, 1,095 days, up to 8 reporters on the same item on the
same day, unmatchable submissions at two per location-day.

```
Done in 1h 16s.
1095 days x 99 locations x 18 items -> 4,208,002 submissions
  (3,791,218 observations, 416,784 queued for review),
  198,712 erroneous and 2,022 manipulated labelled, 1,951,290 ground-truth rows
2,867 rows/second sustained
```

About 14.1 million rows and 4.9 GB, against roughly 21,000 observations in the
shipped demo. **216,810 submissions — 5.15% — have a ground-truth item of null**,
meaning no catalogue entry would have been right. That share is what turns a
recall-only fixture into something precision can be measured against.

Two findings from measuring at that size, and they point opposite ways.

**Index computation gets dearer as history grows.** 340 ms per snapshot against
224 ms at 1.4M observations — 52% more for 2.7× the data. The estimator reads
observations in a window around the date, so its cost tracks the observation
table rather than the location count. A year of backfill across 100 locations is
about 3.5 hours at this size.

**The public API does not.** `index/current` answers in 316 ms at 3.8M
observations against 322 ms at 1.4M — unchanged, because it reads published
snapshots and never touches the observation table. That is exactly what
precomputing the index was supposed to buy, and this is the first evidence it
actually does.

### On my own measurements during the run

Twice I spot-checked throughput by differencing two row counts and got numbers
that were wrong in both directions — once claiming a collapse to 164 rows a
second when a controlled window measured 853. The generator flushes in batches,
so short samples are bursty, and my ad-hoc checks were not an instrument. The
three-minute sampled curve is the one that holds; the spot-checks between them
should not have been reported as findings, and one of them was.

## Phase 20 — the corpus checked against sources, not against a model's confidence

Seven agents were given a research brief on Libyan Arabic — the east/west split,
the phonology that drives spelling, confirmed regional word pairs, Italian
loanwords, and which sources are actually reachable — and told to check every
Libyan wording against the web.

Four verification agents returned **666 verdicts, one per wording**: 592 keep,
35 fix, 39 delete. Every verdict matched a real entry; none referred to a
wording that was not there.

### What was wrong

The errors clustered by *register*, which is not something a spelling check
would find. Words that are perfectly good Arabic but belong to another dialect:

- `قوطه` for tomato is **Egyptian**; `أنبوبة غاز` is Egyptian where Libyan is
  `أسطوانة` or `قروره`; `ظرف` for a sachet is Egyptian where Libyan is `كيس`;
  `حريمي` and `دستة` are Egyptian markers.
- `محفظة` for a school bag is **Tunisian/Algerian** — in Libya it means a wallet,
  so `محفظة الولد` reads as "the boy's wallet".
- `ستيلو` is French *stylo*, so **Moroccan/Algerian/Tunisian**. Libya's European
  lexical layer is Italian, not French — the same reason `Clamoxyl sirop` was
  wrong and `قنينة` (Levantine) should be `قارورة`.
- `بلدي` for free-range poultry and eggs is Egyptian register; the Libyan market
  term is **`عربي`**. Six rows.

Brands with no Libyan presence: Rehydran (Egyptian, five rows), Fevadol (Saudi),
Roco (Gulf retail), Perdix, Frangosul, Doux, Safa. A fabricated brand in a test
set produces a false negative that looks exactly like a matcher bug.

Two findings I could not have reached alone. `ڨاز` applies the western `ق→g`
spelling to a word spelled with `غ`, where the rule does not belong. And
`6ma6em` uses `6` for ط, which is the **Levantine and Gulf** franco-arabe
convention — Libyan and Maghrebi franco-arabe uses a plain `t`, with `9` for ق
and `7` for ح. The Arabizi added last phase was partly in the wrong dialect of
Arabizi.

One entry, `dabba mta3 el cooler / el barrada`, was an authoring artefact: a
slash offering two alternatives inside a single string, which no person types.

### The corpus now knows where a word is from

571 wordings carry a region tag — **west 108, both 450, east 13**. That ratio is
itself the finding: the corpus is heavily western, because Tripoli dominates
what is written online. An eastern reporter in Benghazi or Derna is far less
well represented than a western one, and now that is visible rather than
implicit.

### What could not be done, and why

The hunt phase — finding Libyan grocery businesses and extracting real wordings —
returned nothing, and the agent was right to return nothing. Its own account:

> Padding this list with plausible-sounding Libyan words I did not actually
> observe would be inventing evidence.

Its blocker was the session's WebSearch budget, spent to 200 of 200 on the
dialect research before it ran. It fell back to direct fetches and logged every
one: `big.ly` food pages empty, OpenSooq **403**, DuckDuckGo a **CAPTCHA wall**,
Bing ignoring the query, Mojeek **403**, mo3jam titles without definitions.

**facebook.com and x.com cannot be fetched at all** — tested directly: Facebook
serves a login wall and X an empty app shell, both behind an HTTP 200. Search
snippets were the workaround, and the search budget is what removed them.

### The guard that earned its place

Repairing the corpus broke it in a way a person would not have noticed: four
`heads` entries pointed at wordings that had just been deleted or renamed. Heads
are matched by string, so a stale head silently disables the weighting for that
item rather than failing. The test written last phase caught all four.

### A native speaker corrected the correction

Three corrections from a Libyan speaker, and the first one matters most for what
it says about the method.

**Free-range poultry and eggs are `وطني`.** The verification agent had found the
corpus's `بلدي` to be Egyptian register — correct — and changed it to `عربي`,
which is also not the Libyan word. Six wordings were fixed from one wrong word to
another wrong word, with a plausible-sounding justification attached. Research
against written sources caught that something was off and still landed wrong;
only a speaker knew where.

**A gas cylinder is `اسطوانة`, `بمبلة` or `بومبة` — never `قارورة`.** The agent
had gone the other way, treating `قروره` as the authentic colloquial and
`أنبوبة` as the Egyptian intruder. It was right about `أنبوبة` and wrong about
`قروره`. Nineteen wordings were rewritten, distributed across all three real
words rather than collapsed onto one, so the variety the corpus exists for
survives. `قارورة` remains where it is genuinely right: an oil bottle, a
medicine bottle.

**A wallet is `جزدان` or `تزدان`.** The agent deleted `محفظة` from the school-bag
item on the grounds that in Libya it means a wallet. Deleting it was right;
the reason was wrong — `محفظة` is not the Libyan word for a wallet either. It has
been added to the distractors, where a wallet belongs: a real thing somebody
might type that no catalogue item should match.

One entry, `فرينة عربي`, was left alone. `عربي` there may name a type of flour
rather than local provenance, and guessing at it would repeat exactly the mistake
above.

## Phase 21 — asking whether a Libyan ever wrote it

The previous pass asked agents "does this look Libyan?", which is a judgement,
and they answered confidently and sometimes wrongly. This pass asked a different
question: **"can you find a Libyan writing this?"** — which a search engine can
settle and judgement cannot. The output schema demanded a verbatim flag, a URL
and a quote, so an unsupported claim had nowhere to hide.

Six agents, 431 tool calls. 121 wordings attested — 38 **yes**, 45 **no**, 38
**elsewhere** — with a source URL and quote on 80% of them.

`elsewhere` is the verdict that earns the design: *found it, but every source is
Egyptian, Gulf, Tunisian or Levantine*. That is how foreign register hides, and
it is invisible to a plausibility check.

### What the east actually writes

45 attested wordings were added, and the eastern ones came from live Benghazi
classifieds, verbatim:

- Four more consonant skeletons for a gas cylinder — `بمبة`, `بنبة`, `اسطون`,
  `اسطوانات` — beside the `بمبلة` / `بومبة` already corrected in.
- **`طهي` where the west writes `طبخ`.** Three independent Benghazi ads for
  cooking gas; every western wording in the corpus says `طبخ`. A real east/west
  split, found rather than reasoned.
- **`دجاج` where the west writes `جاج`.** Eastern ads spell it out.
- `بيض وطني` from a Benghazi buyer — independent eastern corroboration of the
  speaker's correction.

East went from 13 tagged wordings to 23. Still thin, but no longer nominal.

### `دحي`

The everyday Libyan word for eggs. `eggs_30` held 37 wordings and not one was
`دحي` — every single one was `بيض`, `بيظ` or `eggs`. It was found twice: a
Libyan paper quoting a homemaker on prices (*"اعتماد المواطن البسيط على الدحي
والجبنة"*), and a Tripoli classifieds listing (`طبق دحي براهما مخصب`).

An item can be full of wordings and still be missing the word people say.

### Libyan brands, from Libya's own price bulletins

The corpus's brands were all foreign — Afia, Orkide, Sadia, NAN. The national
commodity bulletin carries Libyan ones nobody had: flour `الربيع`, `الصفوة`,
`الريحان`; rice `أبو بنت`, `الصحى`, `الأسرة`, `سيلا`; oil **`قورينا`** — Cyrene,
named for the Libyan city. Plus `بيوميل` (Biomil) infant formula with a named
Libyan distributor.

And the trade register the bulletins actually print: `دقيق الأغراض المنزلية` for
1kg household flour, `أرز الحبة الطويلة/القصيرة`, `دجاج جاهز للطبخ`, and the
`بيض عادي` / `بيض مغلف` grade split at 11 against 17 dinars a tray.

`شناطي مدرسية` — the Libyan broken plural of `شنطة`, from a Libyan seller. The
agent called it the best single find of its pass and it is hard to disagree.

### What the agents refused to do

Asked to attest `بيض وطني`, one returned **no** and explained why: it had found
`وطني` used for chicken meat but had not seen the collocation for eggs, and —

> I am marking it "no" only because I refuse to report the speaker's ruling back
> as if it were my own find. Keep the entry.

Another found `صابون طرابلس` and flagged it as a trap: Tripoli, **Lebanon**.

### Four findings held back for a speaker

Recorded in the corpus under `_open_questions` rather than acted on, because
acting on them is exactly the mistake that had to be undone last time:

- **`فرينة`** — roughly 15 of the flour wordings, and every commercial
  attestation found was Algerian or Tunisian. Not one Libyan source.
- **`دبة`** — 10 of the water wordings, unattested across five query shapes.
- **`بيض عربي`** — Tripoli bulletins carry `البيض العربي` as a distinct
  higher-priced grade beside `البيض العادي`. So `عربي` may be a real egg *grade*
  even though `وطني` is the word for local produce.
- **Sanitary pads** — not one wording in the item attested to any Libyan source.

Globally-distributed brands flagged `elsewhere` — Panadol, Lux, Molped — were
left alone. Absence of Libyan evidence is not evidence of Libyan absence, and the
agents said so themselves.

### The corpus was built outward from the formal word

The speaker's next correction: eggs are **`دحي`**, not `بيض`.

`eggs_30` held 43 wordings. Forty-one were built on `بيض` / `بيظ` / `eggs`, and
its head — the set the generator samples most often — was entirely `بيض`. The
everyday Libyan word appeared twice, both added only in the previous phase, and
carried roughly 2% of the sampling weight.

This is the same shape as the two corrections before it. `اسطوانة` over `بمبلة`
and `بومبة`. `عربي` over `وطني`. The corpus was assembled from the word that
appears in writing and worked outward, when a price reporter types the word they
say. Written sources cannot correct that, because the written sources *are* the
formal register — Libyan price bulletins really do print `البيض العادي`.

`دحي` is now the head, and `بيض` is kept but demoted, since the bulletins are
real too. Measured rather than assumed: the generator emits `دحي` in **47.5%** of
egg submissions, against about 2% before.

Only `دحي` and `طبق دحي` were ever observed in the wild. The other eighteen forms
were built by taking frames already attested in the item — `طبق X`, `X ٣٠ حبة`,
`X وطني` — and swapping the head word in. That is a smaller leap than inventing a
word, and it is still a leap, so it is recorded in `_open_questions` for pruning.

## Phase 22 — the first real Libyan data

Asked directly whether Qeema had ever been tested on real Libyan data, the answer
was no: 3.79 million observations, every one synthetic. The corpus work had made
the *vocabulary* real — attested strings from live Libyan listings — but
vocabulary is not data. The matcher had never seen a real submission.

The attestation agents had walked past the answer. Chasing wordings, they quoted
46 real Libyan prices from daily commodity bulletins — شبكة ليبيا التجارية,
republished by Libyan outlets, product / brand / unit / price in dinars, on a
schedule, for items already in the catalogue.

Sixty of those rows went through the live public submission API and were resolved
by the real pipeline.

### What happened

Sixteen matched correctly: 1 kg household flour, short- and long-grain rice,
sunflower and corn oil, each with a real Libyan mill or brand attached — الصفوة،
الريحان، الربيع، الصحى، الأسرة، أبو بنت، سيلا، الجيد.

Forty-one were products the catalogue does not contain — tuna, cheese, sugar,
tea, couscous, harissa. **Not one was refused.** Every one had a match proposed.
Sugar was offered as sanitary pads, tuna as amoxicillin.

**Nothing auto-resolved.** All sixty went to the review queue.

### The finding that matters

Correct matches scored 0.726 to 0.746. Wrong ones scored 0.574 to 0.741, and
**eleven wrong matches scored higher than the lowest correct one**. There is no
threshold that admits the good and rejects the bad on this data.

And the overlap is systematic rather than noisy. It is precisely the SKU
conflation that ruins a price index:

    طماطم معجون  (tomato paste)      → tomatoes_1kg      0.739
    زيت زيتون    (olive oil)         → cooking_oil_1l    0.737
    دقيق المخابز (25 kg bakery sack) → wheat_flour_1kg   0.735
    دقيق اسمر    (barley flour)      → wheat_flour_1kg   0.727

Tomato paste priced as fresh tomatoes. Olive oil priced as sunflower. A 25 kg
sack priced as a kilo.

The synthetic corpus could not have produced this. Its distractors were chosen to
be *clearly* different, because they were written by asking what a wrong answer
looks like. Real market data supplies near-neighbours nobody thought to invent —
and the near-neighbours are where a price index breaks.

### What it means, stated carefully

The review queue caught all of it, which is the design working exactly as
intended. It also means that on data of this kind the matcher automates nothing:
every row needs a person.

A trade bulletin is not reporter text — it is a formal register the matcher was
never tuned for, so this understates how it would do on what a reporter types.
But the near-neighbour confusions are not an artefact of register. A reporter can
type "tomato paste" just as easily as a bulletin can print it.

### Licensing, and why this is not yet an ingestion

The bulletins are real and structured and would slot straight into the existing
scraper subsystem. They are not being ingested. The route they were found
through — libyaakhbar — is an aggregator whose footer reads
`© جميع الحقوق محفوظة`, all rights reserved, which is not an openly-licensed
dataset and has no place in the runtime of an Apache-2.0 project bound by C1.

The primary source, شبكة ليبيا التجارية, needs its own terms checked before
anything is ingested. That check did not happen: the session's search budget was
exhausted by the agents. Sixty rows fetched once for a one-off evaluation is a
different act from a scheduled scrape, and only the first has been done.

## Phase 23 — training on real data, and why it made things worse

Asked why the ML is not simply trained on real data, the honest answer turned out
to have two halves, and the experiment was worth running for both.

### The calibrator had never been fitted

`ConfidenceCalibrator` turns a raw match score into a probability of being
correct. It has never run in any deployment, because fitting needs labelled human
review outcomes and there have never been any. It falls back to a deliberately
conservative shrink toward 0.5 — and the fallback is why every confidence in this
project sits in a narrow band.

Probed directly against the Libyan catalogue: `asdasdasd` returns **0.582** and is
routed to review as sanitary pads. "Used Toyota car" returns 0.588 as rehydration
salts. The floor is not near zero. It is near 0.58, and everything above it is
compressed into 0.2–0.8, which is exactly why correct matches (0.726–0.746) and
wrong ones (0.574–0.741) overlapped in the real-data test.

### Fitting it fixed one thing and broke a worse one

62 labelled outcomes were assembled from the bulletin run — 16 correct, 46 not,
plus two nonsense probes — and posted to `/v1/match/calibrate`. It fitted.

    asdasdasd        0.582 review  ->  0.000 reject
    used Toyota car  0.588 review  ->  0.000 reject
    tuna             0.586 review  ->  0.000 reject
    tomato paste     0.739 review  ->  0.525 reject
    OLIVE OIL        0.743 review  ->  1.000 AUTO-RESOLVE
    rice 1kg  (ok)   0.731 review  ->  0.524 reject

The noise floor was eliminated outright. And in the same move the matcher began
**auto-resolving olive oil as cooking oil with no human in the loop**, while
rejecting correct matches for rice and sunflower oil.

Isotonic regression on 62 points has nowhere sensible to put its steps: every
score landed on 0.524 or 1.000. The curve had 16 positives to learn the correct
side from.

Reverted by restarting the service, and verified reverted.

### The guard was too permissive, and now says why

`MIN_SAMPLES` was 50. Fifty is enough to produce a confident-looking curve from
noise, which is precisely what the class's own docstring warned about and what
its constant then permitted. It is now 300, with a new `MIN_PER_CLASS` of 50, and
two tests encode the failure — one of them fitting the exact 62-sample shape and
asserting it is refused.

### Which half of the problem training can solve

The real-data test produced two different failures and they need different fixes.

**The noise floor is a calibration problem.** Unrelated text scoring 0.58 is the
model being unable to say "I have no idea". Calibration fixes it, and the fix was
visible immediately — it just needs several hundred labelled outcomes rather than
sixty.

**The near-neighbours are not.** Tomato paste scores close to tomatoes because it
*is* close to tomatoes; olive oil is close to cooking oil. The model is not
wrong. The catalogue simply has no entry for what was typed, so "nearest" is the
wrong question to be asking. No amount of training changes that — it needs
catalogue coverage, or explicit negatives, or a reviewer.

Which puts a number on what a pilot is for: roughly three hundred human review
decisions, which only real reporters can generate.

## Phase 24 — what training can and cannot reach

The proposal was to train the ML and loop AI review over it until it is smart.
Three experiments say which parts of that work.

### Calibration: the loop works, and needs more than sixty

Refitted on **898** labelled pairs rather than 62 — every corpus wording labelled
with its own item, every distractor labelled as matching nothing.

    nonsense          0.582 review  ->  0.025 reject
    used Toyota car   0.588 review  ->  0.057 reject
    rice 1kg (ok)     0.731 review  ->  0.873 auto-resolve
    flour 1kg (ok)    0.746 review  ->  1.000 auto-resolve

The noise floor is gone and correct matches now auto-resolve. At 62 examples the
same fit collapsed into steps and auto-resolved olive oil. So the loop is real:
more labelled outcomes genuinely produce a better calibrator.

### And it can never fix the near-neighbours

Isotonic regression is monotonic. It maps scores to probabilities without
reordering them. Checked against the actual numbers:

    raw 0.898   زيت زيتون بكر لتر  -> cooking_oil_1l   WRONG
    raw 0.885   أرز الحبة القصيرة  -> rice_1kg         CORRECT

Olive oil outranks a correct rice match *on the raw score*. No monotonic mapping
of that score can put rice above olive oil — so no amount of calibration, and no
number of loops, separates them. Refitted on 898 examples the near-neighbours got
**worse**: tomato paste, olive oil and the 25 kg bakery sack all moved from review
to **auto-resolve**, which is a wrong price entering the index unreviewed.

Reverted again.

### The lever nobody had pulled

The matcher matches against `canonical_item_variants`. Libya has **133** of them.
The corpus has **689** wordings. The matcher has never been given a single one —
every measurement in the last several phases tested it on vocabulary it does not
have.

Split the corpus in half, added one half to the catalogue, measured on the other
half, which it had never seen:

| | top-1 on held-out wordings |
|---|---|
| catalogue as shipped (133 variants) | 237/351 — **67.5%** |
| catalogue grown with 338 corpus wordings | 304/351 — **86.6%** |

**+19.1 points, on text it had not seen.** That is generalisation, not
memorisation — the test half was held out.

It is also the mechanism the platform was designed around: the review queue adds
variants, which is why `operations.md` says a queue that is worked shrinks and a
queue that is ignored grows. Nobody had ever fed it.

### Where AI review is safe to loop, and where it is not

This session produced direct evidence on both sides. Asked whether `قوطه` is
Egyptian — checkable against sources — the agents were right. Asked which word is
correct for free-range poultry, they replaced Egyptian `بلدي` with `عربي` and
were confidently wrong; a speaker had to supply `وطني`.

Calibration labelling asks "is this match correct" — mostly product identity, is
paste the same as fresh tomatoes, which is checkable and which agents do well.
That is safe to loop.

Dialect correctness is not, and the failure mode is the dangerous one: a loop
distils the labeller's judgement into the model, so a confident systematic error
becomes a systematic model error with no trace of where it came from. Had this
project looped on that review, it would have trained the matcher to prefer
`عربي`.

### Why the +19 points has not been taken yet

Importing the corpus into `ly.yaml` as variants is the obvious next move and it
is not being done blind. The corpus still carries unresolved questions — whether
`فرينة` is Libyan at all, whether `دبة` is, whether the eighteen constructed
`دحي` forms are real. Importing wholesale would bake every one of those into the
matcher's vocabulary, where they would be much harder to find than in a JSON file.

The attested subset can go in now. The rest waits for a speaker.

## Phase 25 — two native-speaker rulings, and the vocabulary finally handed over

### دبة does not mean what the corpus assumed

Ruled by a speaker: **دبة means a bear, or a fat person.** It is not a container.

The corpus had built the entire drinking-water item on the opposite assumption,
and a first grep understated it because the file also spells it `دبه`:

- **23 of 37 wordings** for `drinking_water_20l` were built on it
- **5 of the 6 head slots** — which the generator weights 40/20/12/8/6

So the overwhelming majority of all synthetic drinking-water traffic ever
generated, including the 4.2 million-row run, was reporting a bear. All 23 are
removed. The head is rebuilt from what is actually attested: the two real Libyan
bottled-water brands, then volume and generic forms.

The item is now thin — 14 wordings, no word for the container at all — and that
is recorded as an open question rather than patched with another guess. Guessing
is what produced `دبة` in the first place.

**فرينة is confirmed Libyan**, overriding the research finding that every
commercial attestation located was Algerian or Tunisian. The word is shared
across the Maghreb; the missing Libyan citation was a gap in the sources, not
evidence against it. Its 23 wordings stay. `فرينة عربي` became `فرينة وطني`,
applying the earlier ruling consistently.

### The catalogue was finally given the vocabulary

**548 wordings across 15 items** are now `variants` in `countries/ly.yaml`.
Libya went from **133 to 675** catalogue variants.

Nothing was imported blind. Excluded: wordings already present, wordings filed
under two different items, wordings colliding with a distractor — and every
wording of the three items that still carry open questions
(`drinking_water_20l`, `eggs_30`, `sanitary_pads_10`, 111 wordings). Those wait
for a speaker. As it happens there were no cross-item or distractor collisions
at all, which is the first evidence the corpus is internally consistent.

### What it bought, measured three ways

**Generalisation** — the 50/50 held-out split from the previous phase, on
wordings the matcher had never seen: **67.5% → 86.6% top-1, +19.1 points.**

**Separation, where the vocabulary is covered:**

| | n | median | max |
|---|---|---|---|
| correct match | 555 | **0.990** | 0.990 |
| distractor — matches nothing | 210 | 0.641 | **0.750** |

Zero of 210 distractors reach the correct band. The gap between the worst 5% of
correct matches and the *best* distractor is **+0.240**.

That is the condition that did not exist before. The previous phase proved
calibration could not separate correct from wrong, because olive oil scored
0.898 against a correct rice match at 0.885 and isotonic regression cannot
reorder. **Coverage is what changed it** — not a better model, not a better
calibrator. The matcher stopped guessing and started recognising.

**And where the vocabulary is not covered, nothing changed:**

| | n | median | max |
|---|---|---|---|
| correct, held-out item | 59 | 0.740 | 0.990 |
| wrong, held-out item | 52 | 0.631 | 0.748 |

**25 of 52 wrong matches still score above the 5th percentile of correct ones.**
The old overlap is exactly as bad as it was.

So promotion does not make the matcher cleverer. It moves wordings from the
regime where the problem is unsolvable into the regime where it is already
solved. That is a far more useful thing to know than a single accuracy number,
because it says what the review queue is *for*: every wording worked in the
queue is a wording permanently moved across that line.

**Cost.** 675 variants is 5× the catalogue and batch matching now takes ~300 ms
per text, enough that the client's 10-second timeout trips on batches of 25.
Precision did not degrade — still 0 distractors auto-resolving, 208 to review,
2 rejected.

**Calibration is now viable and is still not fitted.** It would finally do the
right thing for covered vocabulary. But held-out correct matches sit at a median
0.740, and a calibrator fit on this separation would push them below the reject
threshold — turning "unknown wording, send it to a human" into "unknown wording,
throw it away". That trade is a policy decision, not a tuning one, so it is
being left for a person to make rather than made silently here.

### A defect found on the way: the suite was running against live data

`phpunit.xml` set `DB_DATABASE=qeema_test`, and it had no effect. A plain
`<env>` does not override a variable that already exists, and the app container
sets `DB_DATABASE=qeema`. Every in-container test run bound to the live
development database. With 4.2 million submissions sitting in it, 36 tests
failed for that reason alone — and the mild failure mode is the lucky one; the
unlucky one is a test that writes.

Now forced. Host and credentials are deliberately still overridable, because
those legitimately differ between a container, CI and a laptop; the database
*name* never should.

Two related traps, both worth knowing before trusting a green run:

- `bootstrap/cache/config.php` makes `env()` inert, so a cached container
  ignores the corrected value entirely until `config:clear`.
- `api/` is **not** mounted into the app container — only `./countries` is. Code
  and docs in the container are whatever the image was built with, so tests run
  there silently check stale files. The 11 docs failures seen in-container all
  pass on the host.

CI was never affected: it provisions `qeema_test` and passes the settings as
real environment variables. Run the suite on the host, or rebuild the image.

## Phase 26 — the batch endpoint was resolving a batch one text at a time

Growing the catalogue to 675 variants pushed batch matching to roughly 300 ms a
text, enough that the API client's ten-second timeout tripped on batches of 25.
The obvious suspect was the catalogue: five times as many variants to embed.

It was not. The semantic index is already cached by fingerprint, so a catalogue
is embedded once and reused. The cost was on the other side.

`/v1/match/batch` looped over its texts calling `match()`, and `match()` embeds
one query with one forward pass. So a batch of forty paid forty forward passes.
The model is far more efficient given all forty at once:

| texts | one forward pass | per text |
|---|---|---|
| 1 | 139 ms | 139 ms |
| 5 | 391 ms | 78 ms |
| 20 | 1,216 ms | 61 ms |
| 40 | 1,562 ms | 39 ms |

Against 84 ms a text when looped at twenty. More than half the machine was being
thrown away, and the waste grew with batch size — exactly backwards for an
endpoint whose whole purpose is clearing a backlog.

`HybridMatcher.match_many` now embeds the queries in a single pass. Two details
matter beyond the arithmetic:

**Texts already decided never enter the batch.** An exact match on a known
variant is a dictionary lookup. Only texts that genuinely need the model are
embedded — so the better the catalogue's vocabulary gets, the cheaper this
becomes as well as the more accurate. Phase 25 added 548 wordings; every one of
them is now a text that costs nothing to resolve.

### Measured end to end

Through the real endpoint, against Libya's 675-variant catalogue:

| batch | before | after | per text |
|---|---|---|---|
| 1 | 382 ms | 129 ms | 382 → 129 ms |
| 5 | 1,863 ms | 242 ms | 373 → 48 ms |
| 20 | 4,846 ms | 549 ms | 242 → 27 ms |
| 40 | 11,389 ms | 1,111 ms | 285 → **28 ms** |

**Ten times faster at forty**, and the ten-second client timeout that this phase
started with now has an order of magnitude of headroom.

Text the catalogue already knows is faster still, because it never reaches the
model at all: **forty known wordings resolve in 12 ms**, under a millisecond
each. That is the compounding return on Phase 25 — coverage bought accuracy, and
it turns out to buy throughput on the same purchase.

One earlier reading was wrong and is worth correcting rather than quietly
dropping. Halving the catalogue appeared to make matching three times faster,
which suggested per-text cost scaled with catalogue size. It does not: that
measurement used a catalogue the fingerprint cache had never seen, so it was
timing a one-off index build. Re-measured cold, the same call took 15.6 seconds.
The cache was doing its job the whole time and the inference drawn from that
number was simply an artefact of a cache miss.

**The answers must not change.** A test asserts batch and one-at-a-time produce
the same action, reason, candidate ids and confidences for the same inputs,
including the awkward ones — empty text, an exact match, and a string like
`asdasdasd` that resembles nothing. Two more assert the model is called exactly
once for a mixed batch and not at all when every text is already decided.

## Phase 27 — the loop closes, and it used to break the next match

Phase 25 concluded that catalogue coverage is the lever and the review queue is
the mechanism that turns it. That was a claim about code nobody had run, so it
was worth checking rather than repeating.

**The loop exists and is well built.** `ApplyReviewDecision::learnVariant` takes
the phrase that defeated the matcher, stores it keyed on its normalised form so
two spellings that normalise alike become one variant, declines to silently
reassign a phrase already claimed by a *different* item — a genuine ambiguity a
reviewer should see — and invalidates the catalogue cache so the next match sees
it immediately.

**And it works.** End to end, against the live service:

    قزازة ما كبيره للبراد    ->  tomatoes_1kg        0.631  review
    (a reviewer approves it as drinking water)
    قزازة ما كبيره للبراد    ->  drinking_water_20l  0.990  auto_resolve

A phrase that was matching *tomatoes* is correct and auto-resolving after one
human decision.

### The defect that only appears once the catalogue is big

The first call after that review **failed**.

Invalidating the catalogue changes its fingerprint, so the ML service rebuilt
the semantic index from nothing: 676 passages, about eleven seconds, against a
client that waits ten. Every human review broke the next matching call, and a
queue being actively worked would have broken them continuously.

This was invisible at 133 variants and unavoidable at 676. Phase 25 created it.

**Fixed by caching vectors rather than indexes.** A passage embedding depends
only on its text, so a catalogue that grew by one variant shares every other
vector with the version before it. `_passage_vectors` now embeds only what it
has never seen.

| | before | after |
|---|---|---|
| catalogue grown by one variant | full rebuild, ~11 s, **timed out** | **215 ms** |
| first match after a review | **failed** | **97 ms** |
| unchanged catalogue | 35 ms | 35 ms |

Cold start is unchanged and still slow — 41 s for 677 variants including loading
the model — because that genuinely is all new work. It happens once per process,
not once per review, which is the difference that matters.

Five tests cover it: only the new variant is embedded, cached and fresh vectors
are identical, row order survives a repeated text, the cache is bounded, and an
eviction racing with a build cannot shorten an index — assembly reads a local
map, never the cache.

### Cleaning up after the test, and a deletion worth recording

The end-to-end test wrote real rows into the development database. Removing them
deleted more than intended: one of the phrases used, `قالون ما`, is a genuine
corpus wording, so the scale dataset contained **611 generated submissions**
using it. Those and their resolutions went too, along with 607 now-orphaned
ground-truth rows that were deleted afterwards to restore referential integrity.

That is 0.015% of a 4.2 million-row dataset that exists to be regenerated, so
nothing is lost that matters — but it is recorded rather than quietly absorbed,
because "I deleted rows from a dataset a measurement was taken against" is
exactly the kind of thing a reader is entitled to know.

## Phase 28 — the promotion becomes something an operator can run

Phase 25's promotion was the most effective change measured on this platform and
it was done by a throwaway script in `/tmp`. Nobody else could repeat it, no
other country could have it, and nothing recorded what it had refused to promote
or why. That is a gap against both C2 and C3, not a tidiness complaint.

`qeema:corpus:promote --country=<ISO2> [--dry-run]` now does it.

**It refuses four things**, and the refusals are the substance of the command:

| Refusal | Why |
|---|---|
| item listed under `hold` in the corpus | the corpus itself says the item is not verified yet |
| already a variant | so a second run changes nothing |
| claimed by two different items | promoting it teaches the matcher to conflate them |
| also listed as a distractor | the corpus contradicts itself, and picking a side would be a guess |

The hold list moved out of a script's head and into the corpus as data:
`ly.json` now carries `"hold": ["drinking_water_20l", "eggs_30",
"sanitary_pads_10"]`. Country facts belong in country files (C3).

**It writes YAML, not database rows.** The catalogue is country configuration:
it belongs in version control where it can be diffed and argued with, and it has
to survive a clean `docker compose up` without anyone remembering a command (C2).
The rewrite is textual rather than parse-and-re-emit, because re-emitting strips
every comment a maintainer wrote and reorders keys — a reviewable diff becomes an
unreadable one. A test asserts a hand-written comment survives.

**It reproduces the manual promotion exactly.** Run against Libya it reports
nothing to do: 555 already variants, 111 held back. That reconciles with what
was done by hand — 548 promoted plus 7 that were already there.

### Venezuela is deliberately not promoted

The command works country-agnostically; a dry run offers **411 wordings across
15 items**, which is the C3 evidence that the mechanism is not Libya-shaped.

It is not being run. The Venezuelan corpus is model-authored, no Spanish speaker
has read it, and it has no `hold` list because nobody has adjudicated which of
its items are doubtful. Promoting it would put unverified brand names into the
catalogue — precisely the failure the `hold` mechanism exists to prevent, and
precisely what this project spent Phase 25 arguing against. The command is
ready; Venezuela needs what Libya got, which was a speaker.

Twelve tests cover it, including the ones about refusing rather than promoting:
a distractor collision, a wording two items both claim, a held item left
untouched, idempotency, a dry run writing nothing, and a comment surviving.

## Phase 29 — a tenth of the generated text had a word-boundary error

Two defects in `RawTextGenerator`, both known about and both left alone because
neither failed anything. They were found the only way this kind of thing is
found — by printing four hundred lines and reading them.

**Affixes were concatenated without a space.** Measured on Libyan output:
**42 lines in 400 — one in ten** — came out as `لقيتدحي`, `سعرطبق بيض`,
`غلادحي`, `شريتدحيغالي شوي`. That is not a plausible typo. It is a word-boundary
error no Arabic typist makes systematically, and it made a tenth of all affixed
text unmatchable for a reason that has nothing to do with dialect, spelling or
the matcher.

The cause is worth stating because it is a good example of a bug that lives
between code and data: the generator concatenated raw, and the Venezuelan corpus
had quietly compensated by baking spaces into its own affixes — `"el "`,
`" en el abasto"` — while the Libyan one had not. One corpus worked, the other
was broken, and nothing in either file said which convention was in force.

Word boundaries now belong to the generator. Affixes are trimmed and joined with
a single space, so both corpora behave identically and neither gets a double
space.

**Units were stated twice.** 13 lines in 400 read like `كيلوبيض ٣٠٠ حبة` — a
kilo of thirty eggs — or `طبق دحيالكيلو`, a tray of eggs per kilo. Prefixes and
suffixes were drawn independently from pools that both contain unit words, with
nothing checking the wording already carried one.

A corpus now declares its own `unit_words`, including the Arabizi spellings —
`kartouna` is the same claim as `كرتونة` — and the generator will not add a unit
affix to text that already states a unit. The words are country facts, so they
live in the country's file rather than in this class (C3). A corpus that
declares none behaves exactly as before, which is asserted.

| | before | after |
|---|---|---|
| glued affix | 42 / 400 | **0 / 600** |
| two unit words on one line | 13 / 400 | **0 / 600** |

Output now reads like something typed: `جبت بيض`, `بيض ٣٠ حبة تونسي`, `طبق دحي`.

One of the three apparent survivors after the first fix was the measuring
script's fault rather than the generator's — it counted `الكرتونة` as two hits
because its list held both `كرتونة` and `الكرتونة`. Counting distinct unit
*concepts* rather than tokens removed it. The remaining real one was Arabizi.

Five tests cover it, and the awkward one is worth noting: asserting "no double
space" is impossible directly, because the generator also doubles spaces
deliberately. The test filters to lines whose other spaces are single — which
requires a multi-word phrasing to have a second space to look at, something the
first two attempts got wrong and the test caught both times.

## Phase 30 — the dataset regenerated against the corrected corpus and generator

The 4.2 million-row dataset predated three fixes: the wordings that meant *bear*,
the glued affixes, and the doubled units. Every figure measured against it
described text that had those defects in it. Regenerated.

| | previous | now |
|---|---|---|
| submissions | 4,208,002 | **3,204,564** |
| observations | 3,791,218 | 2,837,893 |
| ground-truth prices | 1,951,290 | **1,951,290** |
| unmatchable | 216,810 | **216,810** |
| locations / reporters | 99 / 990 | 99 / 792 |
| database | 4.9 GB | 3.9 GB |

The two identical figures are the useful ones. Ground truth and unmatchable rows
depend only on locations x items x days, none of which changed — and 99 x 18 x
1,095 is exactly 1,951,290. Anything else being equal would have been suspicious.

The submission count fell because the previous run had ten reporters per location
and this one has eight, which is what was asked for. Daily coverage is unchanged
and uniform — about 2,900 submissions a day at the start of the history, in the
middle, and at the end.

**Both text fixes hold at scale**, verified against the finished dataset rather
than a sample: zero glued prefixes and zero lines carrying both a unit prefix and
a unit suffix, in 3.2 million rows.

Four rows do match a naive glued-prefix pattern and none of them is one. They are
the corpus wording `جيبتا كشكول ٨٠` — "I brought an 80-page notebook" — with its
`ي` removed by the typo mutation, leaving `جبتا`, and `جبت` is coincidentally also
a prefix. Chasing that took several wrong turns worth recording: the codepoints
had to be dumped before it was clear there was no space, because a terminal
renders Arabic right-to-left and the string did not look like what it was.

### Two things the run itself demonstrated

**It was killed while printing its summary**, so the command never reported
success. It had finished anyway, and the way to know is worth keeping: reporter
counters are backfilled as the generator's very last step, and
`sum(submissions_total)` across 792 reporters equals the submission count
exactly. A partial run could not produce that.

**No wall-clock figure is quoted.** This run took over four hours against the
previous one's hour, and the cause was the measurement, not the platform — the
same Postgres was being queried throughout to watch the data arrive. The 90-day
block that spanned that querying took 19 minutes against about 3 for its
neighbours. The earlier throughput numbers, taken on a quiet database, stand.

## Phase 31 — a real ML improvement that should not be shipped

The task was to improve the matcher and have independent reviewers check the
improvement was real rather than imagined. Three adversarial reviews ran against
the code, the data and the numbers. The improvement is real. It should still not
be shipped, and the reviews are why.

### What was built

A discriminative verifier: twelve features per match — the two existing scores,
the margin over the runner-up, token coverage each way, digit agreement, and how
much of the query is absent from the matched item's vocabulary and from the
catalogue entirely — fed to a logistic regression and a gradient booster, five-fold
cross-validated with the catalogue rebuilt per fold.

The motivation was measured, not guessed. Calibration provably cannot fix the
near-neighbours because isotonic regression is monotonic in one score; a model
over several features can reorder.

### Root cause, found and verified

`fuzz.token_set_ratio` returns **exactly 100 whenever a catalogue variant's tokens
are a subset of the query's**:

    طماطم معجون بيتي   vs طماطم  -> 100   WRONG  (paste is a different product)
    ارز ابيض كيلو      vs ارز    -> 100   RIGHT  ("white" is not)

The lexical signal is structurally incapable of separating those two. So is the
semantic one — the embedding scores the *wrong* pair higher in both flagship
cases:

    olive oil -> cooking oil   0.8354   vs   sunflower oil -> cooking oil   0.8243
    tomato paste -> tomatoes   0.8615   vs   red tomatoes -> tomatoes       0.8518

`multilingual-e5-large` was tested against `-base` on the same pairs: it repairs
the olive oil inversion by +0.005 and leaves tomato paste inverted. **Scaling the
embedding is not the fix.** Four pairs, so directional only.

Re-weighting the two signals is not the fix either — sweeping the lexical weight
from 0.4 to 1.0 moves AUC 0.847 to 0.851 at best. The shipped weights are fine;
an earlier note here that the weaker signal is over-weighted was correct
arithmetically and wrong in its implication, because the signals are
complementary rather than redundant.

### What the reviewers found

**The leakage was mine, and it was the same bug.** The guard dropped a held-out
wording whose *normalised string* was already in the catalogue — 2 of 876. But the
relation that decides the score is token-subset, not string equality. The corpus
contains one-token wordings (`رز`, `زيت`, `بيض`); a fold promotes them into the
catalogue, where each becomes a **wildcard scoring 1.00 against every query
containing that token**. Having identified that exact asymmetry as the root cause
of the near-neighbour failures, I did not apply it to my own experiment.

Re-run with a token-set guard:

| | as first reported | reviewer's leak-free | strict token-set guard |
|---|---|---|---|
| rows | 873 | 690 | 530 |
| fused baseline AUC | 0.847 | 0.806 | **0.710** |
| GBM verifier AUC | 0.912 | 0.897 | **0.910** |

The verifier sits near 0.90 under every framing; the *baseline* is what moves.
That is a robustness result in the verifier's favour — and the absolute numbers
first reported were not defensible.

**The baseline was soft.** `margin` — the gap to the runner-up — scores 0.857
alone, beating the 0.847 fused baseline, and the matcher **already computes it**
(`_decide` vetoes a margin below 0.05). Dropping `fused` from the twelve features
costs 0.0004. A two-threshold rule over (fused, margin) with no ML at all
recovers roughly three quarters of the claimed gain.

**The gain is almost entirely one class.** Splitting the negatives into
distractors (nothing would have been right) and wrong-item errors (a real price
filed against the wrong item — the kind that corrupts an index):

| | fused | logistic | gbm |
|---|---|---|---|
| vs distractors | 0.837 | 0.915 | 0.911 |
| vs wrong-item | 0.878 | **0.888 (p = 0.53)** | 0.917 (p = 0.041, n = 70) |

**96% of the logistic model's gain is distractor rejection**, and on wrong-item
errors it is indistinguishable from doing nothing — and *worse than `margin`
used alone* (0.931 vs 0.888, p = 0.025). The fitted model destroys signal a
feature it was given already carried.

The effect that does exist is statistically solid: +0.061 AUC, DeLong
p = 9.3e-10, positive in all five folds, and it survives a cluster bootstrap over
the 18 catalogue items — though that clustering means the effective sample is
about 406, not 873, and every row-level interval was ~1.5x too narrow. Logistic
and boosting are **not** distinguishable from each other (p = 0.55), so reporting
both to four decimals implied precision that was not there.

### Why it changes nothing in production

The thresholds apply to `confidence`, not to the fused score, and confidence is
the calibrator's output. The calibrator has never been fitted in any deployment,
and its unfitted fallback is `0.5 + 0.5*(score-0.5)*1.2` — which for a **perfect**
fused score of 1.0 returns **0.80**, below the 0.85 auto-resolve threshold.

Nothing reaches auto-resolve through the scorer at all. The headline metric —
"wrong answers accepted" — describes acceptances that cannot currently happen.
Shipping the verifier today would change zero decisions. And the prerequisite,
fitting the calibrator, is exactly the change Phase 24 measured as moving tomato
paste, olive oil and the 25 kg sack *into* auto-resolve.

### What the evidence actually points at

Of the 210 distractors, **61 fall into 12 real product families** — tuna, cheese,
pasta, tomato paste, sterilised milk, couscous, olive oil, bakery flour, chicken
parts, sugar, harissa, tea. They are labelled "matches nothing" because an
18-item catalogue does not stock them, not because they are nonsense. Adding
tomato paste and olive oil as items turns nine of the most dangerous distractors
into correct auto-resolves at 0.990 through the exact-match short-circuit — no
model, no reviewer, no inference — and yields two new price series where the
verifier's best outcome is a queue item a human rejects.

That is what Phase 23 concluded before any of this: "the catalogue simply has no
entry for what was typed, so 'nearest' is the wrong question." Phase 25 then
measured coverage as the most effective change on the platform. This phase spent
its effort building a classifier to be suspicious of the word معجون, when the
catalogue answers the same question exactly and for free.

**One asymmetry worth fixing regardless.** `ApplyReviewDecision::approve` teaches
the matcher the phrase that defeated it. `reject` teaches it nothing. A reviewer
saying "this is not a product we track" is a label the platform currently throws
away.

### Disposition

`ml/scripts/verifier_experiment.py` is kept. It is a good measurement and it is
what established that the near-neighbour failures are a vocabulary gap rather than
a scoring one. Its output does not go in the resolution path.

## Phase 32 — the two confusions, fixed by catalogue rather than by model

Phase 31 concluded that the near-neighbour failures are a vocabulary gap, not a
scoring one. This acts on that.

**`olive_oil_1l` and `tomato_paste_400g` are now catalogue items** — catalogued,
deliberately **not basketed**, following the precedent of three items that
already ship that way. The published index is untouched; basket weights are a
methodology decision and not one to make as a side effect of a matching fix.

| input | before | after |
|---|---|---|
| زيت زيتون بكر لتر | cooking_oil_1l 0.737 review | **olive_oil_1l 0.990 auto_resolve** |
| طماطم معجون بيتي | tomatoes_1kg 0.739 review | **tomato_paste_400g 0.990 auto_resolve** |
| زيت عباد الشمس لتر | cooking_oil_1l | unchanged |
| طماطم كيلو | tomatoes_1kg | unchanged |

Eight wordings moved from `distractors` to `items`. Two deliberately did not:
**زيتون اخضر مكبوس** is pickled olives and **معجون سنسوداين** is Sensodyne
toothpaste — `معجون` means paste in general, not tomato paste. Both share a token
with a new item and both still go to review rather than auto-resolve, which is
the correct disposition for a product the catalogue does not stock.

No model, no training data, no per-country artifact. The confusion did not get
detected, it stopped existing.

### Two real bugs found by doing it

**`qeema:corpus:promote` reported writing wordings it had not written.** Its
rewriter matched only flow-style `variants: [a, b]`. Promotion *converts* flow
style to block style — so the second run against any item silently promoted
nothing while printing a success message and a count. Every Libyan item has been
block style since Phase 25, so the command shipped last phase was a no-op on the
entire catalogue it was written for.

The test fixture was flow-style throughout, which is exactly why the suite agreed
with it. Fixed: both styles are handled, the fixture now contains a block-style
item, and the rewriter **throws rather than returning** if any planned item was
not written. A command that reports success for work it did not do is worse than
one that fails.

**`qeema:config:import` never invalidated the matcher's catalogue cache.** The
app sends the ML service a cached copy of the catalogue. `ApplyReviewDecision`
invalidates it when a reviewer adds a variant; the importer did not. So an
operator could add a product, import it, and watch the matcher go on ignoring it
until the cache happened to expire — which is precisely what happened here, and
why the first verification run showed 0.752 instead of 0.990.

Both were found by checking the whole lifecycle rather than the diff.

**And one that is real but not worth fixing yet.** `qeema:corpus:promote` cannot
write from inside the app container: `./countries` is mounted read-only, by
design. It failed with a raw `ErrorException`. It now says so, and offers
`--out=` plus the two-line copy-back, or running it on the host.

### Not built: learnRejection

`approve` teaches the matcher the phrase that defeated it; `reject` teaches it
nothing, and closing that asymmetry was the other recommendation. It is not built
because `reject` means two different things — "this is not a product we track"
and "this submission is unusable" — and the reviewer types free text, so the
intent cannot be inferred. Learning from the second kind would be actively
harmful: a reviewer rejecting a rice report because the *price* was absurd would
teach the matcher that أرز matches nothing, and rice would stop resolving for
everyone, permanently.

It needs an explicit signal from the reviewer, which means a schema change, an
action-signature change and a Filament change. Worth doing deliberately, not as
a footnote to a matching fix.

## Phase 33 — seven more product families out of the distractor pile

Same treatment as the two before, applied to the rest of what a Libyan commodity
bulletin listed and an 18-item catalogue could not name.

| item | wordings | unit |
|---|---|---|
| `canned_tuna_185g` | 11 | pack |
| `pasta_500g` | 7 | pack |
| `uht_milk_1l` | 6 | litre |
| `couscous_1kg` | 4 | kg |
| `bakery_flour_50kg` | 4 | 50 kg |
| `sugar_1kg` | 3 | kg |
| `harissa_can` | 3 | pack |

All catalogued, none basketed. The catalogue is 27 items; the basket is still 15.

**Measured after import: 38 of 38 newly catalogued wordings resolve correctly and
all 38 auto-resolve at 0.990. The 164 remaining distractors still produce zero
auto-resolves.** Coverage went up and precision did not move — which is the pair
of numbers that matters, because adding items is exactly the change that could
have bought recall with false positives.

### What was deliberately left alone

Four families that look like families and are not one product:

- **Cheese** (8 wordings) — block cheddar, block mozzarella, slices and triangles
  are different SKUs at different price points.
- **Yoghurt** (6) — `زبادي` and `لبن` are different products.
- **Chicken parts** (4) — breast, thigh and liver. The corpus's own adversarial
  review named this as precisely the distinction a price index exists to
  preserve, and collapsing it would reward the failure mode.
- **Tea** (3) — green and black, split two to one.

Each would have raised the coverage number and quietly taught the matcher to
conflate things that cost different amounts. They stay distractors until somebody
decides what the right codes are.

### What the remaining distractors are now

164, and they are finally what the name says: greetings, test messages, "how do I
register a price", car batteries, gold by the gram, phone credit, a taxi fare
from Tripoli to Tajoura, cement, rebar. Nothing in the list is a grocery a Libyan
shop sells that the catalogue cannot name. That makes the precision figure
measured against them mean what it claims to.

Across the two phases: **46 wordings moved from "matches nothing" to a correct
auto-resolve**, and nine new price series exist where the alternative on offer
was a classifier trained to be suspicious of them.

## Phase 34 — one decision, a thousand rows

Two measurements changed what the next improvement should be.

**First: the scoring path can never auto-resolve anything.** Routing every corpus
wording and distractor through the live matcher:

| | n | auto-resolve | review | max confidence |
|---|---|---|---|---|
| wordings the catalogue knows | 605 | **605** | 0 | 0.990 |
| wordings it does not know | 107 | **0** | 107 | 0.751 |
| distractors | 164 | **0** | 162 | 0.750 |

The uncalibrated confidence ceiling is 0.80 and the auto-resolve threshold is
0.85, so **only the exact-match short-circuit ever auto-resolves**. Lexical
scoring, the embedding, the fusion — none of it can decide anything. It is a
router to humans. And an unknown-but-correct wording (0.751) is indistinguishable
from something that matches nothing (0.750).

**Second: the reject band is unreachable too, and means nothing anyway.** Two of
164 distractors reject; the rest queue. And `ResolveSubmission` routes a `reject`
action to review exactly as it routes `review`, so the two actions are the same
thing. `asdasdasd`, `صباح الخير`, `test 123` and `١٢٣٤` all cost a human.

So the bottleneck is not the model. It is the queue, and the queue is enormous.

### 11.9 identical rows per decision

On the regenerated dataset, **365,595 submissions awaiting review carry 30,851
distinct texts**. The commonest phrase was waiting **1,205 times**.

`learnVariant` already fixed the future — the phrase becomes a variant, so the
next submission carrying it resolves alone. It did nothing about the backlog.
Every row already queued stayed there, resolvable by a matcher that would now
get it right, waiting for a human to repeat a decision just made.

`ClearReviewBacklogJob` applies the decision to every identical row.

**Measured end to end on the live 3.2M-row dataset**, using `دحي` — the word a
native speaker had to correct, and one of the three held items, so genuinely
unknown to the catalogue:

    queued as دحي before:  1,204
    one reviewer decision:    53 ms
    backlog job:            90.7 s
    queued as دحي after:        0
    prices that reached the index instead of a queue: 1,204

Three design decisions are worth stating because each could have been made
carelessly:

**Provenance is `exact`, not `human`.** A reviewer never saw those 1,203 rows.
Recording them as human-approved would claim somebody checked a price nobody
looked at. `fused` would claim a model ran. What actually happened is that the
text matched a variant exactly.

**Reporter reputation is untouched.** The reviewer confirmed what the *phrase*
means, not that a thousand separate prices are honest. Crediting a thousand
reporters for one decision would let a single approval move reputations nobody
examined.

**A row whose price cannot be normalised stays queued.** An unknown unit is a
different question from what the phrase means, and it still needs a human.

Nine tests cover it, including the two that matter most — it runs twice without
changing anything the second time, and it does not touch another country.

### And the queue now shows which decisions are worth most

`Identical rows` sits beside the existing `Basket weight` column, sortable. One
sorts by how much a decision moves the published index; the other by how many
rows it clears. A phrase waiting a thousand times is a thousand rows one click
can remove, and until now nothing in the interface said so.

## Phase 35 — rejecting teaches the matcher too

Phase 34 made approving worth 11.9 rows a click. Rejecting was still worth one,
so the junk was now the expensive half of the queue: `١٢٣٤` was waiting 1,049
times, `test 123` 1,047, `تجربه` 1,033, `السلام عليكم` 1,007, `asdasdasd` 1,002.
Five phrases, **5,138 human decisions**.

`unmatchable_phrases` is the mirror of a catalogue variant. One records what a
phrase means; the other records that it means nothing. Both exist so a human
decision is made once instead of once per submission.

**Measured on the live 3.2M-row dataset:**

    ١٢٣٤            1049 -> 0
    test 123        1047 -> 0
    تجربه           1033 -> 0
    السلام عليكم    1007 -> 0
    asdasdasd       1002 -> 0

    5 decisions removed 5,138 rows

And the future half, on a newly submitted `asdasdasd`:

    resolved in 24 ms   status: rejected   method: rule
    notes: A reviewer ruled this phrase is not a product tracked here: keyboard mash

It never reached the queue, and the matcher was never asked.

### The design decision this rests on

**A plain rejection teaches nothing, and that is the whole point.** `reject`
already meant "this submission is unusable", which covers an absurd price, a
duplicate and a test message — and only the last says anything about the
*phrase*. Inferring the ruling from any rejection is the version of this feature
that destroys the platform: the first reviewer who rejects a rice report because
the price was nonsense would teach the matcher that أرز matches nothing, and rice
would stop resolving for everyone, permanently.

So the reviewer states which they mean. The rejection modal has a checkbox —
"This text is not a product we track" — off by default, and only that path
records a ruling. The test that matters most asserts the negative: reject for any
other reason and nothing is remembered.

Three more guards, each for a way this could do damage quietly:

- **A phrase the catalogue calls a product is refused outright.** Reviewer and
  catalogue disagreeing is a question for a person, not something to settle
  silently in either direction.
- **Reputations are not docked for reporters nobody looked at.** Rejecting the
  submission in front of you is a verdict on that reporter. Rejecting a thousand
  others because they share a phrase is a verdict on the phrase.
- **Nothing is deleted.** A discarded submission keeps `rejected` status and its
  resolution names the ruling and the reason, so a price thrown away by an
  automatic rule is traceable to the decision that threw it away, and deleting
  the ruling restores the old behaviour.

Nine tests, weighted toward those negatives rather than the happy path.

### Where the queue stands

    before phases 34-35 : 367,392 rows / 31,044 texts
    now                 : 360,473 rows / 30,847 texts

The row count moves slowly because only seven phrases have been ruled on so far.
The number that changed is the cost of a decision: an approval is now worth every
identical row and a junk ruling is worth every identical row plus every future
one. A queue of 360,000 rows is 30,847 decisions, and the commonest few thousand
of those cover most of it.

## Phase 36 — the index was publishing nothing, and nothing said so

Having changed the catalogue and the queue heavily, the next thing was to check
the constraints rather than add features. C6 says the public data is the product.

**C6 holds on access**: `/health`, `/countries`, `/index/current` and `/coverage`
all answer 200 without a token. **It did not hold on content.**

    "index": { "level": null }
    "cost":  { "local": 0 }
    "quality": { "coverage": 0, "observed_items": 0, "total_items": 15 }

476 published snapshots, **not one carrying an index level**, sitting on top of
2.8 million price observations. The headline number of the entire platform was
null, and every endpoint returned 200 while saying so.

### Two mechanisms, each individually reasonable

**The generator bulk-inserts.** `insertChunks('price_observations', ...)` is what
makes it fast, and it bypasses Eloquent — so `PriceObservationObserver` never
fires, and nothing marks the affected snapshots stale. The staleness path the
`ChainLinker` docblock refers to ("only fires when new observations arrive")
genuinely exists; the generator simply walks around it.

**Bootstrap only anchors a country with no snapshots at all** — `if ($published)
continue`. Reasonable on its own: re-anchoring a live country would restate
published figures. But once the scheduler had published a handful of empty
snapshots, that guard meant nothing would ever anchor them either.

Neither is wrong alone. Together they produce a platform that serves an empty
index and never says why.

### Fixed, and verified

`qeema:index:link` anchored 99 locations, which marked 396 snapshots for
recomputation; draining them took one command. The public API now:

| location | date | cost LYD | level | coverage | items |
|---|---|---|---|---|---|
| tripoli | 2026-08-15 | 13,255.41 | 500.90 | 73% | 11/15 |
| benghazi | 2026-08-15 | 13,554.30 | 487.59 | 69% | 11/15 |
| khoms | 2026-08-15 | 13,164.52 | 503.53 | 86% | 12/15 |

**99 of 99 locations carry a level**, spanning 460.1 to 539.7.

`qeema:demo:scale` now anchors the basket itself and hands every already-published
snapshot back for recomputation, then prints the one command still needed.
Recomputation is not run automatically: it is minutes to hours depending on the
history, and a command that silently blocks for two hours is worse than one that
says what to run.

Two tests cover it — the basket must be anchored after generation, and the
operator must be told the index still needs computing. Neither existed, which is
why 476 empty snapshots could sit there being served.

### The general lesson, worth keeping

Every gate was green the whole time. Tests passed, Larastan passed, the API
returned 200, the health check said `ok`. The product was empty. **Nothing in the
suite asserted that the number the platform exists to publish is not null**, and
a health check that reports a working pipeline while the pipeline publishes
nothing is a health check measuring the wrong thing.

## Phase 37 — a health check that could not see an empty product

Phase 36 found the index publishing nulls while every gate stayed green. The
check responsible said this:

    publication  ok  "Every country has a figure for today."

It asked whether a snapshot *existed* for today. It never asked whether the
snapshot said anything, so a row with `index_level: null` satisfied it
completely — and the summary then asserted a figure that was not there.

`publication` now distinguishes three states rather than two:

| state | meaning | what the operator does |
|---|---|---|
| behind | no snapshot for today | wait, or look at the publisher |
| **no_level** | snapshots exist and carry no level | **`qeema:index:link` — the basket is not anchored** |
| ok | at least one location carries a level | nothing |

Reported separately because the fix is different and specific. A level is null
when the basket has no anchor, so telling an operator the country is "behind"
sends them to wait for a publisher that is already running perfectly.

Partial coverage stays `ok`: a location with too little data legitimately yields
no level, and the question this check asks is whether the country publishes
anything at all.

### It found a second instance immediately

Run against the live deployment, it degraded on **both** countries — and
Venezuela had never been anchored either. 80 snapshots, none carrying a level, on
a country nobody had looked at. Anchoring it took one command:

    LY  396 of 396 snapshots carry a level
    VE   80 of 80  snapshots carry a level

Venezuela is now a real public series: 16 locations, levels 125.6 to 451.1. It
had been serving nulls for as long as it had been publishing.

### A test that encoded the bug

Adding the check broke an existing passing test — *it is ok when today is
published* — because `IndexSnapshot::factory()` leaves `index_level` null, so the
test asserted that a snapshot carrying no figure counted as published. That is
the same assumption the health check made, written down twice and agreeing with
itself.

The helper now defaults to creating a snapshot **with** a level, so "published"
means "carries a figure" everywhere in the suite, and a test that wants the empty
case has to ask for it.

Four tests: the empty case degrades, the summary names the anchor rather than
blaming lateness, partial coverage stays ok, and the original behaviour still
holds.
