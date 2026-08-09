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
| 6–12 | Not started |

---

## Quality gates — current numbers

| Gate | Result |
|---|---|
| Pest | **293 passed**, 1 skipped, 1,392 assertions |
| PHP coverage | **93.8%** (gate ≥80%) |
| Playwright (offline E2E) | **8 passed** |
| pytest | **132 passed** |
| Python coverage | **94.5%** (gate ≥80%) |
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
