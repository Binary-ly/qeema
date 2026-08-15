# SPDX-License-Identifier: Apache-2.0
"""Cross-validated experiment: can a discriminative verifier beat the fused score?

**The problem this addresses, stated as measured facts rather than intuition.**

For text whose wording the catalogue already knows, matching is solved: correct
matches sit at 0.990 and no distractor exceeds 0.750. For text it does not know,
the two populations overlap badly — correct wordings have a median fused score of
0.740 and wrong ones 0.631, and 25 of 52 wrong matches score above the 5th
percentile of correct ones.

Calibration cannot fix that. Isotonic regression is monotonic in one score, and
the failure is an *ordering* failure: olive oil scores 0.898 against a correct
rice match at 0.885. No monotonic map of that score reorders them.

A model over *several* features can reorder them, if features exist that separate
the cases. The near-neighbour failures all share a shape the fused score throws
away — the query carries a token or a number the matched variant does not:

    زيت زيتون بكر لتر  -> cooking oil   : "زيتون" (olive) appears in no variant
    كيس رز ٥ كيلو      -> rice 1kg      : 5 vs 1
    طماطم معجون بيتي   -> tomatoes      : "معجون" (paste) appears in no variant

This measures whether that is true, honestly.

**Protocol.** Five-fold cross-validation over corpus wordings *and* distractors
together. For each fold the catalogue is rebuilt from the base variants plus every
corpus wording outside the fold, so a test wording is never in the catalogue it is
matched against. The verifier is trained on the other four folds only.

**Leakage guard.** A test wording whose normalised form is already in the fold's
catalogue would be short-circuited to 0.990 by exact match and would inflate every
number here. Those are counted and dropped, and the count is printed rather than
hidden — if it were large the whole experiment would be meaningless.
"""

from __future__ import annotations

import json
import os
import re
import sys
from dataclasses import dataclass

import numpy as np
from rapidfuzz import fuzz
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import roc_auc_score
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

from qeema_ml.api.matching import CatalogueIn, VariantIn, _build_catalogue, _matcher
from qeema_ml.matching.normalise import normalise

FOLDS = 5
DIGITS = re.compile(r"\d+")

#: Drop held-out wordings that get a free perfect lexical score from a sibling.
#: Set false to reproduce the original, contaminated run.
STRICT = os.environ.get("STRICT", "1") == "1"

FEATURE_NAMES = [
    "lexical",
    "semantic",
    "fused",
    "margin",
    "query_token_coverage",
    "variant_token_coverage",
    "unmatched_query_tokens",
    "digits_agree",
    "digits_conflict",
    "length_ratio",
    # The two that matter most conceptually, and were missing from the first
    # attempt. Coverage against the single best-matching *variant* asks the wrong
    # question: an item has many variants, and a query token may appear in
    # another one. What separates a near-neighbour is a token that appears
    # nowhere in the whole item's vocabulary — "زيتون" against cooking oil — or
    # nowhere in the catalogue at all, which is what a distractor looks like.
    "unmatched_vs_item",
    "unmatched_vs_catalogue",
]


@dataclass
class Row:
    features: list[float]
    correct: bool
    query: str
    predicted_code: str
    true_code: str | None
    fold: int


def features_for(
    query: str,
    best,
    runner_up_score: float,
    item_vocabulary: dict[str, set[str]],
    catalogue_vocabulary: set[str],
) -> list[float]:
    """Everything the fused score throws away, plus the fused score itself."""
    variant = best.matched_variant or ""
    q_tokens = [t for t in normalise(query).split() if t]
    v_tokens = [t for t in normalise(variant).split() if t]
    q_set, v_set = set(q_tokens), set(v_tokens)

    shared = q_set & v_set
    q_cover = len(shared) / len(q_set) if q_set else 0.0
    v_cover = len(shared) / len(v_set) if v_set else 0.0

    q_digits = set(DIGITS.findall(" ".join(q_tokens)))
    v_digits = set(DIGITS.findall(" ".join(v_tokens)))
    # Absent on both sides is agreement; present on both and different is the
    # 25 kg sack matched to 1 kg flour, which is the expensive kind of wrong.
    agree = 1.0 if q_digits == v_digits else 0.0
    conflict = 1.0 if (q_digits and v_digits and q_digits != v_digits) else 0.0

    q_len, v_len = len(normalise(query)), len(normalise(variant))
    ratio = min(q_len, v_len) / max(q_len, v_len) if max(q_len, v_len) else 0.0

    item_words = item_vocabulary.get(best.canonical_item_code, set())
    unmatched_item = len(q_set - item_words) / len(q_set) if q_set else 0.0
    unmatched_catalogue = len(q_set - catalogue_vocabulary) / len(q_set) if q_set else 0.0

    return [
        best.lexical_score,
        best.semantic_score,
        best.fused_score,
        best.fused_score - runner_up_score,
        q_cover,
        v_cover,
        float(len(q_set - v_set)),
        agree,
        conflict,
        ratio,
        unmatched_item,
        unmatched_catalogue,
    ]


def main() -> int:
    with open("/tmp/eval_input.json") as handle:
        data = json.load(handle)
    item_ids: dict[str, int] = {k: int(v) for k, v in data["item_ids"].items()}
    base = data["base_variants"]

    # Fold assignment is positional and stratified within each item, so every
    # fold sees every item. No randomness, so the run is reproducible.
    labelled: list[tuple[str, str | None]] = []
    for code, wordings in data["corpus"].items():
        labelled.extend((w, code) for w in wordings)
    labelled.extend((d, None) for d in data["distractors"])

    folds: list[list[tuple[str, str | None]]] = [[] for _ in range(FOLDS)]
    per_group: dict[str | None, int] = {}
    for text, code in labelled:
        n = per_group.get(code, 0)
        folds[n % FOLDS].append((text, code))
        per_group[code] = n + 1

    matcher = _matcher()
    rows: list[Row] = []
    dropped_leaky = 0
    dropped_wildcard = 0
    dropped_nocand = 0

    for fold_index in range(FOLDS):
        held_out = folds[fold_index]
        training_wordings = [
            (t, c) for i, f in enumerate(folds) if i != fold_index for (t, c) in f if c is not None
        ]

        variants = [
            VariantIn(
                canonical_item_id=v["canonical_item_id"],
                canonical_item_code=v["canonical_item_code"],
                text=v["text"],
            )
            for v in base
        ]
        variants += [
            VariantIn(canonical_item_id=item_ids[c], canonical_item_code=c, text=t)
            for (t, c) in training_wordings
        ]

        catalogue = _build_catalogue(CatalogueIn(variants=variants))
        known = set(catalogue.exact)

        # Same-item variants, normalised, for the wildcard guard below.
        same_item: dict[str, list[str]] = {}
        for variant in variants:
            same_item.setdefault(variant.canonical_item_code, []).append(normalise(variant.text))

        item_vocabulary: dict[str, set[str]] = {}
        catalogue_vocabulary: set[str] = set()
        for variant in variants:
            words = {t for t in normalise(variant.text).split() if t}
            item_vocabulary.setdefault(variant.canonical_item_code, set()).update(words)
            catalogue_vocabulary |= words

        for text, code in held_out:
            normalised = normalise(text)

            if not normalised:
                continue

            if normalised in known:
                # Would be short-circuited to 0.990 by exact match. Counting
                # these rather than scoring them is the whole point.
                dropped_leaky += 1
                continue

            # String equality is the WRONG equivalence relation here, and using
            # it was this experiment's most serious flaw. The scorer is
            # ``fuzz.token_set_ratio``, which returns 100 whenever a catalogue
            # variant's tokens are a SUBSET of the query's. The corpus contains
            # one-token wordings — "رز", "زيت", "بيض" — and a fold promotes them
            # into the catalogue, where each becomes a wildcard scoring a perfect
            # 1.0 against every query containing that token. A held-out wording
            # then gets a free perfect lexical score from one of its own item's
            # siblings in the training folds.
            if STRICT and any(
                fuzz.token_set_ratio(normalised, v) >= 100.0 for v in same_item.get(code or "", [])
            ):
                dropped_wildcard += 1
                continue

            decision = matcher.match(text, catalogue)

            if not decision.candidates:
                dropped_nocand += 1
                continue

            best = decision.candidates[0]
            runner_up = decision.candidates[1].fused_score if len(decision.candidates) > 1 else 0.0

            rows.append(
                Row(
                    features=features_for(
                        text, best, runner_up, item_vocabulary, catalogue_vocabulary
                    ),
                    correct=(code is not None and best.canonical_item_code == code),
                    query=text,
                    predicted_code=best.canonical_item_code,
                    true_code=code,
                    fold=fold_index,
                )
            )

        print(
            f"  fold {fold_index}: {len(held_out)} held out, catalogue {len(variants)}",
            flush=True,
        )

    print(
        f"\nscored {len(rows)} | dropped {dropped_leaky} exact-match, {dropped_nocand} no-candidate"
    )

    x = np.array([r.features for r in rows], dtype=np.float64)
    y = np.array([1 if r.correct else 0 for r in rows], dtype=int)
    fold_of = np.array([r.fold for r in rows], dtype=int)

    print(f"positives {int(y.sum())}  negatives {int((1 - y).sum())}")

    fused = x[:, FEATURE_NAMES.index("fused")]
    print(f"\nBASELINE fused score AUC: {roc_auc_score(y, fused):.4f}")

    # Cross-validated: each fold predicted by a model that never saw it.
    # Scaled, because a regularised linear model on features whose ranges differ
    # by an order of magnitude penalises them unequally — the first attempt did
    # this wrong and its coefficients were not readable.
    def build_linear():
        return make_pipeline(StandardScaler(), LogisticRegression(max_iter=5000, C=1.0))

    def build_trees():
        return HistGradientBoostingClassifier(
            max_iter=200, max_depth=3, learning_rate=0.1, random_state=0
        )

    scored: dict[str, np.ndarray] = {}
    for label, build in (("logistic", build_linear), ("gbm", build_trees)):
        out = np.zeros(len(rows), dtype=float)
        for fold_index in range(FOLDS):
            train = fold_of != fold_index
            test = fold_of == fold_index
            model = build()
            model.fit(x[train], y[train])
            out[test] = model.predict_proba(x[test])[:, 1]
        scored[label] = out
        print(f"VERIFIER {label} (cross-validated) AUC: {roc_auc_score(y, out):.4f}")

    print("\nWrong answers accepted, at a threshold set to keep this much recall:")
    print(f"{'recall':>8} {'fused':>12} {'logistic':>12} {'gbm':>12}")
    for target in (0.95, 0.90, 0.80, 0.70, 0.50):
        line = f"{target:>8.0%}"
        for scores in (fused, scored["logistic"], scored["gbm"]):
            cut = np.quantile(scores[y == 1], 1 - target)
            line += f" {float((scores[y == 0] >= cut).mean()):>11.1%}"
        print(line)

    readable = build_linear().fit(x, y)
    coefs = readable.named_steps["logisticregression"].coef_[0]
    print("\nStandardised coefficients (fitted on everything, for reading only):")
    for name, coef in sorted(zip(FEATURE_NAMES, coefs, strict=True), key=lambda kv: -abs(kv[1])):
        print(f"  {coef:+8.3f}  {name}")

    print("\nThe near-neighbours this was built for:")
    probes = ["زيت زيتون", "معجون", "٥ كيلو", "٢٥ كيلو", "اسمنت"]
    for i, q in enumerate(rows):
        if any(p in q.query for p in probes) and not q.correct:
            print(
                f"  fused {fused[i]:.3f} -> logistic {scored['logistic'][i]:.3f}"
                f"   {q.query}  ->  {q.predicted_code}"
            )

    payload = {
        "features": FEATURE_NAMES,
        "x": x.tolist(),
        "y": y.tolist(),
        "fold": fold_of.tolist(),
        "queries": [r.query for r in rows],
        "predicted_code": [r.predicted_code for r in rows],
        "true_code": [r.true_code for r in rows],
        "verifier_scores": scored["logistic"].tolist(),
        "gbm_scores": scored["gbm"].tolist(),
    }
    with open("/tmp/eval_output.json", "w") as handle:
        json.dump(payload, handle)
    print("\nwrote /tmp/eval_output.json")

    return 0


if __name__ == "__main__":
    sys.exit(main())
