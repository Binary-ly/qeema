<!-- SPDX-License-Identifier: Apache-2.0 -->

# Running a first pilot

For the person who has a working deployment and now needs real prices flowing
through it.

[deployment.md](deployment.md) covers installing it. [operations.md](operations.md)
covers keeping it running. This covers the thing neither can: the first few weeks
with real reporters, when nothing is yet known about whether any of it works
outside a simulation.

## Contents

- [What a pilot is for](#what-a-pilot-is-for)
- [Before anyone reports anything](#before-anyone-reports-anything)
- [The first cohort](#the-first-cohort)
- [Week one](#week-one)
- [When the figures can be published](#when-the-figures-can-be-published)
- [Turning the pilot into evidence](#turning-the-pilot-into-evidence)
- [What is most likely to go wrong](#what-is-most-likely-to-go-wrong)

## What a pilot is for

Not to prove the software runs. That is already checked on every push, on a
clean machine, by CI.

The pilot exists to answer questions the software cannot answer about itself:

1. **Will people report?** Everything downstream is worthless without this, and
   it is the one thing no amount of engineering can establish.
2. **Does the matcher understand how people actually write?** It scores 98.4%
   against generated text with dialect and typos. Real reporters will write
   things the generator never imagined.
3. **Do the estimates survive contact with a real market?** Every machine
   learning figure in [assessment.md](assessment.md) was measured against a
   simulation whose price process this repository defines. They are upper
   bounds, not predictions.
4. **Is the review queue workable by one person?** If a week of submissions takes
   longer than a week to review, the design is wrong and better to know at 20
   reporters than at 500.

A pilot that answers only the first question is still worth running. A pilot
that answers none of them because nobody looked at the data is not.

## Before anyone reports anything

**Fix the basket, then leave it alone.** A revision mid-pilot is survivable —
that is what chain-linking is for — but it costs you comparability of your
smallest and most precious sample. Decide the basket with whoever knows what
children in that country actually need, and change it only if something in it
turns out to be unbuyable.

**Set a base period you will actually have data for.** `index.base_date` is
honoured exactly and will report loudly if no data exists there. Leaving it unset
anchors the series at the first day the basket can be priced in full, which for a
pilot is usually what you want.

**Decide the exchange rate question early.** In a crisis economy the parallel
rate moves faster than prices do, and `cost_usd` is withheld entirely once the
last rate passes the staleness horizon. If you are entering rates by hand, decide
now who does that and how often, because a fortnight of null dollar figures is
the sort of thing that gets noticed after the fact.

**Start every item catalogued, even ones outside the basket.** The matcher can
only resolve what it knows about, and an item catalogued from the start can be
added to a later basket version with a chain link rather than a break.

**Walk through the reporter app in the language reporters will use it in.** Not
in English. Not on your laptop. On a mid-range Android phone, outdoors, on a
degraded connection, in the app's own default locale.

## The first cohort

**Small and known beats large and anonymous.** Twenty people you can call is a
better first cohort than two hundred you cannot, because in week one you will
need to ask somebody "what did you mean by this entry", and that question is only
answerable if you know who they are.

**Identity is a client-supplied UUID.** There is no signup, by design. A reporter
who clears their browser storage becomes a new reporter with a fresh reputation.
For a first cohort this is fine and the openness is worth more than the
precision. It stops being fine when the cohort is large enough that you cannot
recognise the participants, and there is no automatic point at which the platform
will tell you that you have crossed that line.

**Cover locations narrowly rather than broadly.** Four markets with four
reporters each produces a usable index. Sixteen markets with one reporter each
produces sixteen unverifiable numbers, because a single reporter's price has
nothing to be checked against — the estimator, the anomaly screen and the
reporter-bias detector all work by comparison.

**Say plainly what happens to what they send.** Prices are published openly under
an open licence. Photographs have their location metadata stripped before the
file reaches disk. Nothing else about the reporter is collected, because nothing
else is asked for.

## Week one

Check these daily. All of them are one command or one page.

**Is the loop closing?**

```bash
curl -s localhost:8080/api/v1/health | jq .pipeline
```

Everything `ok` means submissions are being resolved, screened, folded into the
index and published without anyone touching it. Anything `degraded` or `stalled`
is diagnosed in [operations.md](operations.md#the-signals-and-what-to-do-about-each).

**What could the matcher not decide?**

Admin → Review queue. In week one this is the most informative screen in the
platform: it is a list of the ways real people write that the matcher did not
anticipate. Work it daily. Every confirmed decision teaches it the phrase that
defeated it, so a queue that is worked shrinks and a queue that is ignored grows.

If the queue is more than about a fifth of submissions, the catalogue is missing
variants people actually use — add them to the country file and re-import rather
than resolving the same phrase by hand every day.

**Is anyone reporting at all?**

```bash
curl -s localhost:8080/api/v1/countries/<ISO2>/coverage | jq '.locations[] | {slug, days_since_update, mean_coverage}'
```

A location that has gone quiet is a person who has stopped, and the fix is a
phone call rather than a code change.

**What is being estimated rather than observed?** `imputed_share` on the
published figures. Early in a pilot this will be high, and that is expected — it
falls as coverage builds. What matters is that it is *falling*.

## When the figures can be published

There is no threshold the platform enforces, because the honest answer depends on
what the figure will be used for. But three conditions are worth insisting on
before anyone quotes a number publicly:

- **`comparable` is true** on the snapshots being compared. It is false until
  every basket item has a price, and a partially-observed basket costs less
  simply because part of it is missing.
- **Coverage is dominated by observation rather than imputation.** A basket that
  is mostly model output is a model's opinion about a market, not a measurement
  of one.
- **At least two reporters per item per location.** One reporter is an anecdote
  the platform cannot check.

Until then the data is still worth collecting, still worth publishing as raw
submissions, and not yet worth quoting as an index.

## Turning the pilot into evidence

This is the part most likely to be skipped, and it is the part that makes the
pilot worth having run. Every ML figure the project currently claims was measured
against a simulation. A pilot is the only thing that can replace those numbers
with real ones, and only if somebody deliberately captures the ground truth.

**Matching.** Every review-queue decision is a labelled example: raw text a human
mapped to a catalogue item. After a few weeks you have a real test set. Compare
what the matcher proposed against what the reviewer chose — that ratio is the
real top-1 accuracy, and it is the single most defensible number a pilot can
produce.

**Anomaly screening.** Note in the review queue when a flagged price turns out to
be genuine. Precision measured against real errors is worth more than the
synthetic 78% recall, and the honest expectation is that it will be worse.

**Coordinated manipulation.** The synthetic figure — 5.3% recall per observation,
6 of 8 reporters caught at the reporter level — cannot be validated in a pilot
unless somebody actually attempts manipulation. Do not claim it has been.

**Nowcasting.** Hold out a location for a fortnight: keep collecting there but
exclude it from the model's training, then compare its imputed prices against
what was actually reported. That is a real backtest and it takes no extra
tooling.

Record the results, including the ones that are worse than the synthetic figures.
The assessment document exists to be updated with them.

## What is most likely to go wrong

In rough order of how often it happens rather than how bad it is:

**People stop reporting after the first week.** The most common failure and the
least technical. The app asks for very little, but "very little, every day,
unpaid" is still a lot.

**One location carries the whole dataset.** Everything still works and the
national picture is quietly a picture of one town.

**The review queue is ignored for a fortnight.** It grows faster than it can be
drained afterwards, and the matcher stops improving because nothing is teaching
it.

**The exchange rate goes stale and dollar figures vanish.** Correct behaviour,
surprising the first time.

**A reporter is flagged who has done nothing wrong.** Expected: the reporter-bias
detector's precision on synthetic data is 6 in 9. It flags for a human and blocks
nobody, deliberately. Talk to the person before assuming anything.

**Everyone reports the same three items.** The basket needs all of them, and the
weight of the missing ones counts against coverage. Ask directly for the ones
that are missing rather than waiting for them.
