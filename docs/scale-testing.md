<!-- SPDX-License-Identifier: Apache-2.0 -->

# Testing at scale, and against harder text

The shipped demo is about 21,000 observations of reporter text that the matcher
was, in a specific sense, built to handle. This describes a second dataset that
is neither.

## What this is for, and what it is not for

**It is not validation.** Nothing here converts a figure measured against a
simulation into a figure measured against a market. The corpus this dataset uses
was authored by a language model, so its realism is *asserted* rather than
measured — it is a harder test, not a real one. Figures produced against it must
not be quoted as though a pilot had produced them. That distinction is the whole
reason [assessment.md](assessment.md) separates what is proven from what is
simulated, and this dataset belongs firmly on the simulated side.

It exists for three things the demo genuinely cannot answer:

1. **Does the platform work at volume?** Nothing tested it above ~21,000 rows.
2. **Does the matcher survive text it was not tuned against?** See below — this
   is the subtle one.
3. **Does the review queue stay workable when it is large?**

## The circularity this breaks

`RawTextGenerator` produces reporter text by mutating catalogue names: it
reintroduces hamza on a bare alef, switches digits to Arabic-Indic form, inserts
a typo, doubles a space.

Those are the same transformations the matcher's normaliser undoes. Both were
written from one list, by one author, at one time. So a matching score measured
against that text is substantially a measure of *whether the normaliser was
implemented correctly* — and only partly a measure of whether it survives how
people actually write. The two cannot be separated by looking at the number.

`countries/corpus/<code>.json` breaks that. Its phrasings are brand names,
colloquial terms, abbreviations and phone-typing slips that no rule the matcher
knows produced.

**Measured, not asserted.** Every Libyan phrasing was normalised with the
platform's own `TextNormalizer`, then compared by trigram similarity against
every known variant of the *correct* catalogue item:

| Similarity to any known variant of the right item | Share |
|---|---|
| below 0.2 — effectively unreachable lexically | **85.2%** |
| 0.2 to 0.8 | 4.2% |
| an exact match to a catalogued variant | 10.7% |

The distribution is bimodal: a wording is either already in the catalogue, or it
is nowhere near it. **85% of this corpus cannot be resolved by trigram matching
at all** — it has to reach the embedding matcher or the review queue. That is
the property that makes it a harder test, and it is a fact about the data rather
than a claim about it.

## Generating a dataset

The corpus is used only when asked for. `qeema:bootstrap` is untouched by it, so
the demo — and every figure measured against the demo — is exactly what it was.

```bash
# A clean database with reference data but no demo history. The generator
# inserts exchange rates without upserting, so it needs a country with none.
docker compose exec app php artisan qeema:bootstrap --force --fresh --skip-demo

docker compose exec app php artisan qeema:demo:scale \
    --country=LY \
    --days=1095 \
    --locations=85 \
    --reporters=8 \
    --reports-per-cell=6
```

| Option | What it does |
|---|---|
| `--days` | Length of history. Drives everything else linearly. |
| `--locations` | How many corpus municipalities to add before generating. Coordinates are checked against the spread of the country's existing locations and a place outside it is rejected and named. |
| `--reporters` | Reporters created per location. |
| `--reports-per-cell` | How many different reporters may report the same item, in the same place, on the same day. This is the main volume multiplier, and it is what gives the estimator and the anomaly screen something to compare. |
| `--observation-rate` | Share of cells that get any report at all. Defaults to the demo's own rate. |

Run it against a country with no submissions. It refuses otherwise, rather than
aborting partway on a duplicate exchange rate and leaving a database nobody can
reason about.

## What was measured

A single completed run: 99 locations, 18 items, 1,095 days of history, up to 8
reporters covering the same item on the same day, and unmatchable submissions at
two per location-day.

| | |
|---|---|
| submissions | **4,208,002** |
| observations | **3,791,218** |
| resolutions | 3,991,192 |
| ground-truth prices | 1,951,290 |
| **unmatchable — no right answer exists** | **216,810 (5.15% of submissions)** |
| queued for review | 416,803 |
| labelled erroneous / manipulated | 198,712 / 2,022 |
| locations / reporters | 99 / 990 |
| distinct days | 1,095 (2023-08-16 to 2026-08-14) |
| database size | **4.9 GB** |
| wall clock | **1 h 00 m 16 s** |
| sustained rate | 2,867 rows/second |

About 14.1 million rows across the tables that matter, against roughly 21,000 in
the shipped demo — a factor of 180.

The 5.15% unmatchable share is the number that changes what can be measured.
Those rows carry a ground-truth item of null: no catalogue entry would have been
right. Until they existed, a matching score computed against this platform's own
data could only ever be a recall figure.

### At that size

| Operation | 1.4M observations | 3.8M observations |
|---|---|---|
| Index computation, one day across ~115 locations | 26.2 s | **39.1 s** |
| per snapshot | 224 ms | **340 ms** |
| `GET /countries/LY/index/current` | 322 ms | **316 ms** |
| `GET /countries/LY/coverage` | 220 ms | **117 ms** |
| `GET /health` | 25 ms | 25 ms |
| Anchoring 99 locations | — | 28.3 s |

Two things worth drawing out.

**Index computation gets more expensive as history grows** — 52% more per
snapshot for 2.7× the observations. The estimator reads observations in a window
around the date, so its cost tracks the size of the observation table rather than
the number of locations. A year of backfill across 100 locations is roughly
3.5 hours at this size. Fine nightly; not something to put in front of a user.

**The public API does not.** `index/current` answers in the same time at 3.8M
observations as at 1.4M, because it reads published snapshots — one row per
location per day — and never touches the observation table. That is the intended
consequence of precomputing the index rather than aggregating on read, and it is
the first evidence the design actually delivers it.

### Insert throughput as the table grows

Sampled every three minutes through a single run to three million rows:

| Rows in `price_observations` | Observations per second |
|---|---|
| 286,000 | 2,205 |
| 866,000 | 1,526 |
| 1,284,000 | 1,110 |
| 1,638,000 | 876 |
| 1,842,000 | 1,117 |
| 2,057,000 | 1,186 |
| 2,438,000 | 1,062 |
| 2,740,000 | 790 |
| 3,051,000 | 883 |

Throughput does fall as the table grows — roughly 2,200 a second early against
850 or so past three million rows — but **not monotonically**, and the shape
matters more than the endpoints. The trough at 1.64 million is not a property of
the database: a test suite was running against the same Postgres instance at that
moment, and throughput recovered to 1,186 once it finished. Several other samples
are similarly noisy.

So the honest reading is a broad downward trend of roughly 60% across an order of
magnitude of table size, with enough measurement noise that no single pair of
points should be trusted. Anyone sizing a bulk import should measure at the size
they will actually be at, on an otherwise quiet database, rather than
extrapolating from an empty one — which is the practical point, and it survives
the noise.

The index figure is the operationally important one: **a full year of backfill
across 117 locations would take roughly 2.6 hours.** That is fine for a nightly
catch-up and too slow to sit in front of a user, which is worth knowing before
somebody discovers it during a pilot. The per-snapshot cost is dominated by the
bootstrap interval — 500 draws per item — so it scales with `bootstrap_draws`,
which is configurable per country.

The history endpoint is not reported: only one day of index had been computed, so
the measurement would have described an almost-empty series rather than a large
one.

## What the corpus is worth, honestly

It was reviewed adversarially by a second model, which found real problems. The
concrete ones were fixed: fourteen Venezuelan entries were filed under a product
code that is a genuinely different SKU — a growing-up milk under stage-1 infant
formula, cornstarch under baby cereal, amoxicillin-clavulanate under amoxicillin,
a ready-to-drink electrolyte bottle under rehydration sachets, an 18 kg gas
cylinder under the 10 kg code. Those are the most damaging kind of error in a
test set, because they teach a matcher to conflate things that cost different
amounts. One entry was removed as classist rather than incorrect.

The most important finding is not a list of bad rows, it is structural, and it
is worth stating in the reviewer's own terms: **the codes are category labels but
the phrasings under them are SKU phrasings, spanning very different price
points.** A new gas cylinder filed against a refill. Chicken breast fillet filed
against whole chicken per kilo. A 10 kg crate of tomatoes filed against a kilo.
A matcher that scores well on those has learned to collapse exactly the
distinctions a price monitor exists to preserve — so the corpus would *reward*
the failure mode rather than catch it. Twenty-one Libyan entries naming a plainly
different product were deleted for this reason.

What could not be fixed by deleting: pack, carton and dozen wordings are
realistic things to type and still sit against single-unit codes, because there
is no catalogue code for "a carton of chicken" to move them to. They remain, and
they are a known distortion rather than a hidden one.

Three of the review's findings have since been addressed, and it is worth being
precise about what changed.

**Precision can now be measured.** The corpus carries **distractors**: 136 for
Libya, 105 for Venezuela, wordings that match no catalogue item at all. They are
tagged by kind — another product entirely, a fragment too vague to resolve, a
greeting or test message typed into the wrong box, and `near_miss`, which is the
valuable one: something adjacent to a catalogued product that a careless matcher
would wrongly match. Semolina against wheat flour. Chicken breast against whole
chicken. Augmentin against amoxicillin. Those three were previously filed as
*positives* and deleted as mislabels; they now appear as things that should be
refused, which is where they belong.

The generator emits them as submissions whose ground-truth row has a **null
item** — the record that no catalogue entry would have been right. They carry no
resolution at all, because nothing matched, which is a different state from
having matched badly. A test asserts no distractor is also a catalogue wording,
since such a row would be scored as a false positive when matching it was
correct.

**The distribution is no longer flat.** Each item declares a *head* — the three
to six wordings that would genuinely dominate real traffic, most common first —
and the generator samples by weight rather than uniformly. Uniform sampling tests
the long tail far harder than reality does and the head far less, so a matcher
could look good on the corpus while failing on the phrasings that would be most
of the actual traffic.

**Arabizi is present.** 180 franco-arabe wordings were added for Libya —
`9arora gaz`, `garoura ghaz`, `dabba ma` — digit substitution for Arabic sounds,
spelled inconsistently the way real people spell it. A large share of Libyans
type this way and none of it was represented.

Two weaknesses remain, and they still bound what the dataset can tell you:

**A dozen entries are unanswerable.** Categories terminate in a bare noun — "oil",
"rice", "gas" — which cannot be resolved to a 1 litre bottle rather than a 5
litre jerrican from the string alone. Marking a matcher wrong for not guessing is
unfair; marking it right rewards guessing.

**No native speaker has read it.** The Arabic and the Spanish are model-authored.
A brand that does not exist produces a false negative that looks like a matcher
bug, and the reviews flagged brands in both languages they could not confirm
against a real shelf. The Libyan dialect itself was judged credible — the
lexicon, the possessive `متاع`, the real LPG distributor, thumb-typing slips that
look like slips rather than synthetic noise — but credible is not verified.

## Reading a number produced against this

State the dataset alongside the figure, always. A matching accuracy measured here
and a matching accuracy measured against the demo are not comparable, and neither
is comparable to one measured against a pilot. If a figure from this dataset ever
appears in `assessment.md`, it belongs under *what is measured only against a
simulation*, with the corpus named.
