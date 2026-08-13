<!-- SPDX-License-Identifier: Apache-2.0 -->

# Chain-linking the index across basket revisions

## The problem

`cost_local` is the cost of *one specific basket*. Revise the basket — add an
item, change a quantity, drop something that stopped being sold — and the series
steps for a reason that has nothing to do with prices. Anyone plotting
`cost_local` across the revision reads the step as inflation. It is not; it is a
different bundle.

This is the last methodological gap listed in `docs/assessment.md`, and it is
the one a statistician would find first.

## What already exists

More than expected, and none of it connected:

| Piece | State |
|---|---|
| `baskets.version`, `effective_from`, `effective_to`, `isEffectiveOn()` | Built and used |
| `Country::basketOn($date)` — the basket in force on a date | Built and used by the publisher |
| `index_snapshots.normalized_index` | Column exists, three Filament fields bound to it, **nothing ever writes it** |
| `index.base_date` in `countries/*.yaml` | Configured, flows into `indexSettings()`, **nothing ever reads it** |
| Items deliberately outside basket v1 | `ly.yaml` keeps three items catalogued but unbasketed, commented as being there to exercise this exact path |

So the shape was designed and the mechanism was never implemented. A reviewer
opening the admin panel today sees a "normalized index" column that is blank on
every row, and the factory fills it with `100.0` so no test ever noticed.

## The construction

Give every basket version a **per-location reference cost** `R_v(L)`, and the
level is then uniform across all versions:

```
level(L, t) = 100 × cost_v(L, t) / R_v(L)
```

**First version.** Anchored at the base period (see D-24):

```
R_1(L) = cost_1(L, base_period)        →  level(L, base_period) = 100
```

**Each later version.** Both baskets are costed on the same day — the last day
the old basket was in force, `d = v_new.effective_from − 1` — and the ratio
carries the anchor forward:

```
link_factor(L) = cost_new(L, d) / cost_old(L, d)
R_new(L)       = R_old(L) × link_factor(L)
```

The level is then continuous at the changeover by construction, and every
subsequent movement is driven purely by prices. This is the standard chain-link;
expressing it as a reference cost rather than as a spliced series means nothing
downstream has to know a link happened.

## Decisions

**D-19 — Link per location, fall back to the country.** Locations differ in what
the revision does to their bundle, so each gets its own factor. A location that
cannot cost both baskets completely on `d` uses the country-wide median factor
and is recorded as having done so. A location with no previous anchor gets no
anchor and a null level, which is honest.

**D-20 — Never anchor on an incomplete basket.** A cost is only usable for
linking when `coverage + imputed ≥ 0.999`, the same bar `isComparable()` already
sets. Anchoring on a half-covered basket would bake a coverage artefact into
every future level.

**D-21 — Anchors are immutable once written.** `qeema:index:link` refuses to
overwrite without `--force`. Recomputing an anchor from whatever data has since
arrived would silently restate the entire published history behind it.

**D-22 — Costing must not persist.** Linking has to cost the *new* basket on a
date when the *old* one was in force. Writing that as a snapshot would publish a
figure for a basket that was not in force on that day. `IndexCalculator` is
therefore split: `cost()` computes and returns, `calculate()` costs and persists.

**D-23 — Rename `normalized_index` to `index_level`.** The column has never been
written and is not in the public API, so nothing depends on the name. "Normalized"
does not say what it is; it is a chain-linked index level with 100 at the base
period.

**D-24 — A base period is measured, not wished for.** A configured `base_date`
is honoured exactly and reported loudly when there is no data there: it is the
operator's assertion about when their series starts, and anchoring elsewhere
would publish a series whose 100 is not the date they documented. With none
configured, the base period is the first day the basket could be priced in full —
recorded on the anchor and fixed from then on. The shipped country files leave it
unset, because the demo's history window always ends today and rolls forward, so
any fixed date drifts out of range and leaves every location unanchored.

**D-25 — A date is no longer unique per location.** Snapshots under both versions
exist around a changeover, so every index endpoint orders by basket version and
takes the highest. Found live: the API was serving the superseded basket for
dates the new one governed.

**Status:** complete. Verified live against the demo stack with a staged basket
revision (since reverted): the importer closed v1 the day before v2 began, the
linker chained 16 locations with a country factor of 1.0894, and the level was
continuous at the link date to 5×10⁻⁸ per cent while the cost moved 2.56%.

Three unrelated dead seams were found while making this reachable, all recorded
in `PROGRESS.md`: `index_config`/`fx_config` were never imported at all, there
was no command to apply a country-file edit, and a revision left both basket
versions in force at once. A fourth defect was found live — every index endpoint
assumed one snapshot per location per date, which a revision makes false, so the
API served the superseded basket.

## Work

1. `BasketCost` value object; split `IndexCalculator` into `cost()` and `calculate()`
2. `basket_links` table + `BasketLink` model — one anchor per (basket, location), with provenance
3. `ChainLinker` service implementing the construction above
4. `qeema:index:link` command; idempotent, refuses silent overwrite
5. `IndexCalculator` writes `index_level` from the anchor
6. Public API exposes `index_level` and `basket_version`
7. Tests, including a real v1 → v2 revision proving continuity
8. Docs, `PROGRESS.md`, and the assessment's gap list
