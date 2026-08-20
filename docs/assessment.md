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

### A basket revision does not break the series

`cost` is the cost of one specific basket, so revising the basket steps the
series for a reason that is not a price. Every snapshot now also carries a
chain-linked `index.level`: each basket version is anchored by a per-location
reference cost, and a new version is carried forward by costing **both** baskets
on the last day the old one was in force and multiplying by the ratio.

Continuity at the link is exact by construction rather than approximate — the two
levels agree to 5×10⁻⁸ per cent, which is float rounding. Proven three ways: unit
tests holding every price constant across a revision, where the correct index
must not move at all while the cost must jump; a mutation check confirming that
removing the link factor makes the level jump by exactly the bundle ratio; and a
staged revision run against the live demo's 21,395 observations, where the cost
moved 2.56% and the level did not move.

Anchors are immutable once written, because recomputing one would silently
restate every figure already published behind it.

### The reporter runs under a strict content-security policy

The reporter app needs no `unsafe-eval`. It runs on Alpine's CSP build, which
evaluates no expressions at all: a template may name a property or a method and
nothing else, so every derived value lives in the component rather than in the
markup. That is the whole cost, and it buys the strict policy for the pages that
accept input from the public.

Asserted rather than assumed — a test walks `/`, `/docs`, `/api/v1/health`,
`/report` and `/offline` and fails if any of them permits `eval`.

### Quality gates

| Gate | Result |
|---|---|
| PHP suite (Pest) | 674 passed, 1 skipped, 2,493 assertions, **94.0%** coverage |
| Python suite (pytest) | 224 passed, **85.4%** coverage |
| Browser suite (Playwright) | 28 passed against the composed stack |
| Static analysis (PHPStan level 6) | 0 errors |
| Formatting and typing (Pint, ruff, mypy) | clean |
| Country-agnostic check | pass |

## Measurements against real Libyan data

Two, sixteen months apart, against the same source. The second is current; the
first is kept because the comparison is the point.

### August 2026 — the bulletin re-run, with a grown catalogue

`ml/scripts/real_text_evaluation.py`, reproducible. Two sets of product names
that **no language model wrote**:

- **166 Arabic strings** from `nashrah.ly`, the daily bulletin of Libya Trade
  Network under the Ministry of Economy and Trade — the same publisher as the
  2025 test below.
- **35 English strings** from WFP's own Libya price dataset on HDX.

The labels are ours; the text is not. Deciding that `أرز الحبة القصيرة الصحى`
means `rice_1kg` is a judgement; every string being real is a fact. Where a
mapping was arguable — chicken liver against a `chicken_1kg` item — the row was
made a distractor rather than a generous positive.

### The number that matches how the platform works

Nothing auto-resolves. Every submission goes to a human who picks from a
candidate list, so top-1 measures a decision the platform does not currently
make, and **recall@k** measures the one it does.

| | on 488 unseen real wordings |
|---|---|
| top-1 | 90.78% (443/488) |
| top-2 | 98.57% (481/488) |
| top-3 | 98.77% (482/488) |
| **top-5** | **99.59%** (486/488), 95% CI [98.5%, **99.9%**] |

For **two** wordings in 488 the right item is absent from the list entirely:
`وجبة مسحوق الارز` and `تن المعمورة كثلة درجه اولى`. For everything else the
reviewer is shown the answer and only has to recognise it.

This is not 99.9% top-1 and should not be read as it. It is the honest statement
that a reviewer working this queue finds the right item in the list 99.6% of the
time, which is what determines whether the review step is workable — and it is
the strongest true claim available about this matcher.

| | |
|---|---|
| Top-1 on wordings **not already in the catalogue** | **90.8%** (443/488), 95% CI **[87.9%, 93.0%]** |
| Top-1 across all positives | 91.6% (537) — 49 were already catalogue variants, so this measures memorisation |
| Real non-basket products **wrongly auto-resolved** | **0 of 485** |
| Real non-basket products refused | **1 of 485** |
| Positives auto-resolved without a human | **0 of 488** |

The honest number is the first row, and the interval is wide because the unseen
sample is 29 wordings. It is not a claim about thousands.

**On 20 August 2026 the evaluation grew from 29 unseen wordings to 488 and the
number fell from 96.6% to 85.2%, then a catalogue fix took it to 89.3%.** The model did not get worse. Eight agents
collected 1,812 wordings from Libyan shop pages; half of each item's new
vocabulary went into the catalogue and half was held out, and the held-out half
is what the table above reports. 96.6% was 28/29 — a sample where one string was
worth 3.4 points and no value between 96.6% and 100% was even representable. The
interval narrowed from ±16 points to ±3, and the point estimate moved to where
the truth was all along.

Two rounds deliberately targeted the hardest cases — bulk versus household flour,
olive versus cooking oil, formula versus drinking milk — so the test set got
harder as it got bigger. That is the right direction for a test set and the wrong
direction for a headline.

**What did improve, measurably.** Eighty-six wordings attested on public Libyan shop pages were
added to the catalogue, and `تن جنزور (زيت الذرة) وطني` — a Libyan tuna brand
whose head noun `تن` had been losing to the `زيت الذرة` in its own name — stopped
resolving to cooking oil. One row on a 29-row set moves the headline three and a
half points and the confidence intervals overlap almost entirely. The change
worth reporting is that a known failure mode was closed, not that the percentage
went up. None of the 86 wordings appears in the evaluation set; that is checked
before any of them is added.

**What improved.** In April 2025 the same bulletin produced 16 correct matches
out of 60, because 41 of those products were not in the catalogue at all. The
catalogue has since grown from 133 variants to 1,320, and coverage is no longer
the binding failure. Confidence is.

### The 99.9% question, answered with arithmetic

Asked to take the matcher to 99.9%, three things had to be established.

**On top-1 it is not reachable, and on 29 rows it was not even representable.**
One error on 29 costs 3.45 points, so there is nothing between 96.55% and 100%.
A 99.9% *point estimate* needs at least 1,000 rows with at most one error. For a
95% lower bound to clear 99.9% you need **3,838 consecutive correct matches**.
The set is 488.

**Some rows cannot be resolved from the string at all.** `حليب بريزدن` is a
brand attached to the bare word for milk; nothing in it distinguishes drinking
milk from infant formula. A ceiling made of genuinely ambiguous strings is not
moved by more vocabulary.

**But 99.9% is the wrong question, and the right one has a good answer.** A price
index does not publish best guesses. It publishes what it auto-resolved and
queues the rest, so what matters is the precision of what goes out unreviewed —
counting an auto-resolved distractor as the error it is.

| Tier | Coverage | Precision |
|---|---|---|
| Exact catalogue match | 9.1% of wordings | **100.0%** (49/49, and 0 of 485 distractors accepted) |
| Fuzzy, at any threshold | up to 82% | never above 78% |

So the platform can already publish unreviewed at ~100% precision over roughly a
tenth of submissions, and everything else belongs in a human queue. That is a
defensible operating policy today.

**Why the fuzzy tier cannot be pushed there yet.** The confidence score separates
correct matches from distractors at **AUC 0.883** — real information, nowhere near
the ~0.99 that 99.9% precision at useful coverage would need. Raising it is a
calibration and embedding problem, not a vocabulary one, which is why
`ml/notebooks/finetune_colab.ipynb` exists.

**One thing the current safety record does not mean.** "0 wrongly auto-resolved"
is true and, on its own, vacuous: the auto-resolve threshold is 0.85 and nothing
in the fuzzy tier scores above 0.75, so the platform is safe because it never
decides, not because it decides well. The table above is the honest version.

### The one fix that moved the number, and why it is not tuning

72% of all errors were three sibling pairs — bakery flour against household
flour, tomato paste against tomatoes, olive oil against cooking oil. In each,
the **bare head noun was a variant of one sibling and of nothing else**: `دقيق`
belonged to `wheat_flour_1kg`, `طماطم` to `tomatoes_1kg`, `زيت` to
`cooking_oil_1l`. So every flour string in the language had an exact anchor on
the one-kilo bag, and `شكارة دقيق` — a fifty-kilo sack at roughly sixty times
the price — resolved to it.

The fourth ambiguous pair, infant formula against drinking milk, has no bare
`حليب` owned by either side. It produced **4%** of errors against the other
three's 72%. That contrast is what turned a hunch into a diagnosis.

Removing thirteen bare nouns moved top-1 from **85.2% to 89.3%** and distractor
separation from **0.850 to 0.873**. Both at once is the part worth noticing:
retrieval accuracy is easy to buy by making items look more alike, which wrecks
the ability to refuse, and this did the opposite.

It is a catalogue-correctness fix rather than test-set tuning, and the rule
stands on its own: **a word that names two products in the basket must not
resolve confidently to either.** It belongs in the review queue — which is
plainly right when the two readings differ in price by a factor of sixty.
`api/tests/Feature/Country/CatalogueVariantPlacementTest.php` enforces it, and
was checked against the old catalogue to confirm it fails there.

### What is left, and what it would take

52 errors remain on 488 unseen wordings. They are not scattered:

| Share | Confusion | Why |
|---|---|---|
| 33% | tomato paste → tomatoes | `طماطم البركه` is a brand on a bare noun. The tin is not named in the string; only the shop's aisle says so |
| 23% | bakery flour → household flour | The catalogue **has** شكارة, شوال and the `50ك` forms, so this is no longer missing vocabulary. `دقيق العزيزية الفاخر50ك` still loses to the brand-plus-فاخر pattern |
| 6% | drinking milk → infant formula | `حليب` plus a brand, with no litre and no `أطفال` |
| ~3pp | specification, not model | Sixteen rows state a pack size their own item code contradicts; see `ml/data/real-text/README_facebook_addendum.md` |

The first and third are **irreducible from the string alone**. `زيت بعلي وليس
مروي` is olive oil only if you know Libyan farming vocabulary — the word زيتون is
not in it. No amount of catalogue vocabulary resolves a string that does not
carry the distinguishing information; a reporter's photograph or a follow-up
question does.

So the remaining gap is not a data problem. It is a **confidence** problem: AUC
0.873 against the ~0.99 that 99.9% precision at useful coverage would need.

**And it is not a fine-tuning problem either, which was worth finding out.** The
experiment was run on 20 August 2026 and the result is negative: twelve epochs
cost **7.5 points of top-1 and 0.128 of separation**, one epoch costs 22. The
untrained `multilingual-e5-base` scores top-1 **99.3%** with separation AUC
**0.942** on that retrieval task, because the catalogue now carries 1,307
attested Libyan wordings. There is nothing for 497 training pairs to add to a
model already at the ceiling of the task.

That reverses the result recorded on 2026-08-16, which had fine-tuning improving
top-1 from 80.3% to 87.1%. That measurement was taken against a 712-wording
snapshot in `/tmp`; it was a statement about a thin catalogue and it stopped
being true when the catalogue stopped being thin. It survived four days only
because nobody could re-run it.

**The fusion weight is not a lever either.** Swept 0.2 to 0.8 on a salted dev
half of the held-out set and reported once on the other half: top-1 is flat at
90.7% across the whole range on dev, and identical on holdout at 0.4 and 0.8.
Reweighting does not change which item wins, because the two signals agree on
the argmax and disagree only on the margin.

So of the six things that could have closed the gap, five are now measured
rather than assumed:

| Lever | Result |
|---|---|
| More vocabulary | 1,812 wordings harvested; lifted the retrieval baseline to 99.3% |
| Catalogue structure | The head-noun fix: **+4.1 points** |
| Fine-tuning the embedder | **−7.5 points.** Rejected on evidence |
| Fusion weight | No effect at any setting |
| Test-set label audit | ~3 points are a specification question; left unchanged |
| Fitting the calibrator | **Not attempted — it needs real human review decisions, which need the pilot** |

### A sixth lever, built rather than rejected: the size the reporter stated

The largest error class was two items sharing a head noun and differing only by
size — a 50 kg trade sack against a 1 kg bag, sixty times apart in price. About
one query in seven **states** the size, so the evidence was in the text all
along.

Measured first, as everything here is now. Against `default_quantity` the signal
picked the right sibling **33 times out of 52** — worse than useless, since it
would have introduced nearly as many errors as it removed. The cause was not the
idea: seven items declare `1 pack` while their own code states a real quantity,
so a 400 g tin is stored as one pack and the size lived in the code *string*
rather than in the data. Given a `pack_size` field carrying the real content, the
same measurement gives **45 out of 45**.

| | before | after |
|---|---|---|
| top-1 on 488 unseen | 89.3% | **90.8%** |
| distractor separation | 0.873 | **0.883** |
| wrongly auto-resolved | 0 / 485 | 0 / 485 |

Both moved the right way, which is the part that matters: retrieval accuracy is
easy to buy by blurring items together, and that destroys the ability to refuse.

**It is wired end to end, not into the benchmark.** The service is stateless and
Laravel owns the catalogue, so a signal that only existed in the evaluation
harness would be a number the product does not have. `units.aliases` and
`canonical_items.pack_size` are columns; the importer fills them from the country
file; `MlClient` sends them with every catalogue; the request schema accepts them
and the cache fingerprint covers them, so an edited alias cannot serve a stale
index. Both fields default to empty, so an older caller gets exactly its previous
behaviour.

`default_quantity` was deliberately left alone. It multiplies through basket
costing, and the last time a quantity moved without its unit the published figure
came out a thousand times too high.

### 3,887 strings, and an honest verdict on what that buys

Asked to reach 3,838 consecutive correct — the count that puts a 95% Wilson
lower bound at 99.9% — the one property in this repository where a number that
size is both attainable and meaningful is the **normalisation contract**.

`normalise()` exists twice: in Python for the matcher, in PHP for seeding and
the Postgres trigram queries. The Python module calls that duplication dangerous
and it is right — text normalised one way at index time and another at query
time simply fails to match, and nothing errors. It was guarded by **22
hand-written fixtures**.

Both implementations were run over every distinct real string the repository
holds — catalogue variants, corpus wordings, distractors, evaluation rows.
**3,887 strings, 3,887 agreements, zero disagreements**, now recorded in
`contracts/text-normalisation-corpus.json` and asserted by both suites.

**And then it was tested against the thing it was meant to strengthen, which is
where it stops being a good story.** Each of the eight character folds was
disabled in turn:

| Injected drift | 22 fixtures | 3,887 real strings |
|---|---|---|
| أ إ آ ة ى ؤ ئ unfolded | caught | caught |
| **ٱ (alef wasla) unfolded** | **caught** | **missed** |

The fixtures win, eight to seven. They were written to cover every fold on
purpose, one case each; real text contains only what people write, and nobody
writes alef wasla. **Volume does not subsume design.**

So this is a complement, not a replacement: it covers digits, punctuation and
glued sizes as they actually co-occur, and checks idempotence over 3,887 strings
rather than a handful. It is worth keeping and it is not what the target asked
for. The target asked for the matcher to be right 3,838 times running, and the
matcher is right 443 times out of 488.

**A sixth was tried and rejected on the numbers.** Libyan sellers write sizes
glued to words — `الفاخر50ك`, `25كيلو` — and the normaliser leaves them as a
single token that matches nothing, while the spaced form `الفاخر 50ك` tokenises
correctly. Splitting on letter/digit boundaries is a real fix to a real defect,
and it moves top-1 by **+0.17 points**: 436/488 becomes 435/486, so the correct
count actually falls by one and the apparent gain is two rows shifting into the
exact-match bucket. Precision at 0.75 drops from 78.3% to 77.4%.

Against that, `normalise` is duplicated in PHP, both halves are pinned to shared
fixtures in `contracts/text-normalisation.json`, and changing it rewrites every
stored `normalized_text` the trigram index depends on. A three-part change plus
a reindex, for a fifth of a point inside the noise. Not done, and recorded here
so the next person does not spend an afternoon rediscovering it.

The last row is the honest answer to why the confidence signal is what it is.
Everything else has been tried.

**What did not improve, at all.** Nothing auto-resolves and nothing is refused —
the two structural findings from 2025 are unchanged. Every one of the correct
matches still went to the review queue, and so did all 123 products that belong
in no basket. Growing the catalogue fixed coverage; it did not touch
calibration, and the section below explains why it cannot.

**Both misses are the same failure, and it is the one that matters.** A modifier
beat the head noun: `Tomatoes (paste)` resolved to `tomatoes_1kg`, and
`تن جنزور (زيت الذرة) وطني` — tuna packed *in corn oil* — resolved to
`cooking_oil_1l`. Confirmed now in two languages, on text written by a UN agency
and a Libyan ministry. It is the same confusion the synthetic corpus surfaced,
which is the one respect in which that corpus proved honest.

One caveat on scope, unchanged from 2025: a trade bulletin is a formal register,
not reporter text, so this understates performance on what a reporter would type
— and says nothing at all about whether reporters will report.

### April 2025 — the original sixty rows


Sixty rows from a Libyan daily commodity bulletin — شبكة ليبيا التجارية, 13 April
2025, product / brand / unit / price in dinars — were pushed through the live
public submission API and resolved by the real pipeline. This is the only figure
in this document taken from data the platform did not generate itself.

| | |
|---|---|
| Rows submitted | 60 |
| Correctly matched to a catalogue item | **16** |
| Products not in the catalogue at all | 41 |
| Of those, **refused** | **0** |
| Auto-resolved without a human | **0 of 60** |

Two findings, and the second is the serious one.

**The matcher never refuses.** Every one of the 41 products absent from the
catalogue — tuna, sugar, cheese, tea, couscous — was matched to *something*
rather than declined. Sugar was proposed as sanitary pads; tuna as amoxicillin.

**Confidence cannot separate right from wrong here.** Correct matches scored
0.726–0.746. Incorrect ones scored 0.574–0.741, and **eleven wrong matches scored
above the lowest correct one**. No threshold exists that admits the good and
rejects the bad on this data.

The overlap is not random — it is systematic, and it is the failure mode that
corrupts a price index specifically:

| Real bulletin row | Matched to | Confidence | Why it is wrong |
|---|---|---|---|
| طماطم معجون (tomato **paste**) | tomatoes_1kg | 0.739 | A different product at a different price |
| زيت زيتون (**olive** oil) | cooking_oil_1l | 0.737 | Several times the price of sunflower |
| دقيق المخابز (**25 kg** bakery sack) | wheat_flour_1kg | 0.735 | Twenty-five times the quantity |
| دقيق اسمر شعير (**barley** flour) | wheat_flour_1kg | 0.727 | A different grain |

What saved it is that **nothing auto-resolved**: all sixty went to the review
queue, so a person would have caught every one. That is the design working. It
also means that on data of this kind the matcher currently automates nothing —
every row needs a human — which is a real operational fact worth knowing before a
pilot rather than during one.

One caveat on scope. A trade bulletin is not reporter text; it is a formal
register the matcher was never tuned for, so this understates performance on
what a reporter would actually type. The near-neighbour confusions, though, are
not an artefact of register: a reporter is just as able to type "tomato paste".

### Why the confidence numbers are compressed

Chasing the overlap above turned up the cause. `ConfidenceCalibrator` maps a raw
match score onto a probability of being correct, and **it has never been fitted**
— not in any deployment, because fitting requires labelled human review outcomes
and there have never been any. It runs a deliberately conservative fallback that
shrinks every score toward 0.5.

That is why keyboard mash scores 0.58. `asdasdasd` against the Libyan catalogue
returns 0.582 and is routed to review as sanitary pads; "used Toyota car" returns
0.588 as rehydration salts. The floor is not near zero, it is near 0.58, and the
whole usable range is squeezed into 0.2–0.8.

Fitting it on the 62 real labelled outcomes above — the most this project has
ever had — fixed that completely and broke something worse:

| | uncalibrated | fitted on 62 |
|---|---|---|
| `asdasdasd` | 0.582 review | **0.000 reject** |
| used Toyota car | 0.588 review | **0.000 reject** |
| tuna | 0.586 review | **0.000 reject** |
| tomato paste | 0.739 review | 0.525 reject |
| **olive oil** | 0.743 review | **1.000 auto-resolve** |
| rice 1 kg *(correct)* | 0.731 review | 0.524 reject |

Isotonic regression on 62 points collapsed into a two-step function — every score
landed on 0.524 or 1.000 — so it began auto-resolving olive oil as cooking oil
with no human in the loop, while rejecting correct matches. A wrong price
entering the index unreviewed is worse than an uncalibrated one going to a
person.

The fit was reverted. The guard that permitted it has been raised from 50
labelled examples to 300, with at least 50 of each outcome, and the reasoning is
recorded in the code and in a test rather than in someone's memory.

**This is the clearest statement available of what a pilot is for.** The
calibration is not a modelling problem waiting on a better model; it is waiting
on a few hundred human review decisions, which only real reporters can produce.

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

**No shipped country has actually had a basket revision.** The mechanism is
tested and was exercised end to end against the live demo, but that revision was
staged and reverted. The first real one will be the first time the operator
runbook is followed by somebody who did not write it.

**A link factor is a single day's measurement.** It permanently scales
everything after it, and it is only accepted when both baskets can be priced in
full — but "in full" counts imputed items as priced. On a day when part of the
basket is estimated rather than observed, the factor carries that estimate's
error into the anchor. Both costs and the factor are recorded on the anchor so a
suspicious step can be traced, but choosing the link date for high *observed*
coverage is left to the operator rather than enforced.

**Automated exchange-rate sourcing for most currencies.** The platform reads any
JSON endpoint an operator configures, and ships configured with none. The
parallel-market rates that matter in these economies are behind API keys, and
depending on one would breach the no-proprietary-services constraint. Manual
entry is the default, and the health check warns before a stale rate withdraws
dollar figures.

**Fitted models are lost if the ML container's volume is lost.** They are
persisted and restored across restarts, and regenerated by the next training run
otherwise — so this costs one scheduled run, not any history.

**The admin panel and Horizon still need `unsafe-eval`.** Both are Filament and
its dependencies rather than code written here, both sit behind authentication,
and neither can be changed without replacing the framework that renders them.
Everything a reporter or a passer-by touches — the reporter app, the dashboard,
the API, the docs and the export — is on a strict policy.

## The constraints, and evidence for each

The project was built to six hard constraints. Each is checked mechanically
rather than asserted.

| | Constraint | Evidence |
|---|---|---|
| **C1** | No proprietary or paid service in the runtime path; all models local from open weights; all dependencies OSI-licensed | Dependency licence inventory generated in CI; embedding weights baked into the image at build time and loaded offline; no exchange-rate or scraper source configured by default; a browser test asserts no request leaves localhost |
| **C2** | Self-hostable in one command | CI builds and boots the whole stack from scratch on every push, then runs the browser suite against it |
| **C3** | Country-agnostic | A CI job greps application source for country literals and fails the build on one — it has caught three, including a currency code that reached the published API contract and a timezone in a comment |
| **C4** | Apache-2.0 end to end | LICENSE, NOTICE, CONTRIBUTING, CODE_OF_CONDUCT; SPDX header on every file |
| **C5** | ≥80% unit coverage, enforced in CI from the first phase | 94.0% and 85.4%, both gated |
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
