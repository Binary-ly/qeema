# SPDX-License-Identifier: Apache-2.0
"""How well does the matcher do on text that no language model wrote?

**Why this exists.** Every matching figure this project has ever published was
measured against `countries/corpus/*.json`, which its own header calls
"authored by a language model … realism is asserted rather than measured". The
87.1% top-1 from the fine-tuning work is a number about a simulation. A funder
asking for "results of initial testing" is not asking for that.

This measures the same matcher against **product names written by other people**
— a government price bulletin and, where available, merchant catalogues. The
text was collected, not generated. Nobody involved in writing it had seen this
catalogue.

**The labels are mine, the text is not.** Deciding that `أرز الحبة القصيرة الصحى`
means `rice_1kg` is a judgement I made; every string being real is a fact. That
is the ordinary construction of an evaluation set and the honest way to describe
it. Where a mapping was arguable — chicken liver against a `chicken_1kg` item —
the row was made a distractor rather than a generous positive.

**Two numbers, because one of them flatters.** Some real wordings are already
catalogue variants, promoted from the corpus in an earlier phase. Those
exact-match and short-circuit before any model runs, so scoring them measures
memorisation. The headline number is therefore computed on **unseen** wordings
only, with the full-set figure reported beside it for comparison.

Distractors are the other half. A bulletin that prices cement, rebar, sheep feed
and thirty vegetables supplies genuinely hard negatives — the kind an invented
distractor list does not contain — and refusing them is as important as
resolving the positives.

Run from `ml/`:  .venv/bin/python scripts/real_text_evaluation.py
"""

from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path

import numpy as np
import yaml

sys.path.insert(0, "src")

from qeema_ml.matching.lexical import LexicalIndex
from qeema_ml.matching.matcher import (
    CatalogueIndexes,
    HybridMatcher,
    MatcherConfig,
)
from qeema_ml.matching.normalise import normalise
from qeema_ml.matching.semantic import SemanticIndex, SentenceTransformerEmbedder

REPO = Path(__file__).resolve().parents[2]
COUNTRY = REPO / "countries" / "ly.yaml"


def build_catalogue(embedder: SentenceTransformerEmbedder) -> tuple[CatalogueIndexes, set[str]]:
    """The country's real catalogue, built the way the service builds it."""
    config = yaml.safe_load(COUNTRY.read_text(encoding="utf-8"))

    rows: list[dict] = []
    for item_id, item in enumerate(config["canonical_items"], start=1):
        texts = [item["name_en"]]
        if item.get("name_local"):
            texts.append(item["name_local"])
        texts.extend(str(v) for v in (item.get("variants") or []))

        for text in texts:
            rows.append(
                {
                    "canonical_item_id": item_id,
                    "canonical_item_code": item["code"],
                    "text": text,
                    "normalized_text": normalise(text),
                }
            )

    exact: dict[str, tuple[int, str]] = {}
    for row in rows:
        key = row["normalized_text"]
        if key:
            exact.setdefault(key, (row["canonical_item_id"], row["canonical_item_code"]))

    vectors = embedder.encode_passages([r["normalized_text"] for r in rows])
    semantic = SemanticIndex(
        item_ids=[r["canonical_item_id"] for r in rows],
        item_codes=[r["canonical_item_code"] for r in rows],
        embeddings=np.asarray(vectors, dtype=np.float32),
    )

    catalogue = CatalogueIndexes(
        lexical=LexicalIndex.from_rows(rows),
        semantic=semantic,
        exact=exact,
    )

    return catalogue, set(exact)


def wilson(hits: int, n: int) -> tuple[float, float]:
    """95% Wilson interval.

    Reported because the honest sample here is tens of rows, not thousands, and
    a bare percentage from n=29 invites a confidence the data does not support.
    """
    if n == 0:
        return 0.0, 0.0
    z, p = 1.96, hits / n
    d = 1 + z**2 / n
    centre = (p + z**2 / (2 * n)) / d
    half = z * ((p * (1 - p) / n + z**2 / (4 * n**2)) ** 0.5) / d

    return max(0.0, centre - half), min(1.0, centre + half)


def main() -> int:
    # Default to every evaluation set the repository ships, so that a reviewer
    # who clones this and runs it bare gets the published figure.
    #
    # This used to default to /tmp/real_wordings.json. On the machine where the
    # number was first produced that file existed, so the script ran and the
    # docstring's claim that the evaluation "is reproducible from the
    # repository" looked true. On a clean checkout it was not: the path does not
    # exist, and even where it did it covered only the Ministry bulletin and
    # silently left out WFP's commodity names. A figure nobody else can
    # reproduce is not a measurement, and this one is quoted to a funder.
    paths = [Path(a) for a in sys.argv[1:]] or sorted(REPO.glob("ml/data/real-text/*.json"))
    dataset: list[dict] = []
    for path in paths:
        dataset.extend(json.loads(path.read_text(encoding="utf-8")))

    if not dataset:
        print("No evaluation rows found. Expected ml/data/real-text/*.json", file=sys.stderr)
        return 1

    positives = [r for r in dataset if r["expected"]]
    distractors = [r for r in dataset if not r["expected"]]

    print(f"source:      {', '.join(p.name for p in paths)}")
    print(f"positives:   {len(positives)}   distractors: {len(distractors)}")

    # Built from the deployed settings rather than hard-coded, so this measures
    # the model and prefixes the service actually uses.
    from sentence_transformers import SentenceTransformer

    from qeema_ml.config import get_settings

    settings = get_settings()
    embedder = SentenceTransformerEmbedder(
        model=SentenceTransformer(settings.embedding_model),
        query_prefix=settings.embedding_query_prefix,
        passage_prefix=settings.embedding_passage_prefix,
        batch_size=settings.embedding_batch_size,
    )
    print(f"model:       {settings.embedding_model}")

    catalogue, known = build_catalogue(embedder)
    print(f"catalogue:   {len(known)} distinct normalised variants\n")

    matcher = HybridMatcher(MatcherConfig(), embedder=embedder)

    decisions = matcher.match_many([r["text"] for r in positives], catalogue)

    seen_rows: list[tuple[dict, object]] = []
    unseen_rows: list[tuple[dict, object]] = []
    for row, decision in zip(positives, decisions, strict=True):
        bucket = seen_rows if normalise(row["text"]) in known else unseen_rows
        bucket.append((row, decision))

    def score(rows: list[tuple[dict, object]]) -> tuple[float, Counter]:
        if not rows:
            return 0.0, Counter()
        hits = 0
        actions: Counter = Counter()
        for row, decision in rows:
            best = decision.best  # type: ignore[attr-defined]
            actions[decision.action] += 1  # type: ignore[attr-defined]
            if best is not None and best.canonical_item_code == row["expected"]:
                hits += 1
        return hits / len(rows), actions

    unseen_top1, unseen_actions = score(unseen_rows)
    all_top1, _ = score(seen_rows + unseen_rows)

    unseen_hits = round(unseen_top1 * len(unseen_rows))
    low, high = wilson(unseen_hits, len(unseen_rows))

    print("TOP-1 ON REAL TEXT")
    print(
        f"  unseen wordings   {unseen_top1:6.1%}   ({unseen_hits}/{len(unseen_rows)})"
        f"  95% CI [{low:.1%}, {high:.1%}]  <- the honest number"
    )
    print(
        f"  all wordings      {all_top1:6.1%}   ({len(positives)} rows)"
        f"  includes {len(seen_rows)} already in the catalogue"
    )
    print(f"  actions on unseen {dict(unseen_actions)}")

    if distractors:
        noise = matcher.match_many([r["text"] for r in distractors], catalogue)
        refused = sum(1 for d in noise if d.action == "reject")
        auto = sum(1 for d in noise if d.action == "auto_resolve")
        print("\nDISTRACTORS — real products that are not in the basket")
        share = refused / len(distractors)
        print(f"  refused outright      {share:6.1%}  ({refused}/{len(distractors)})")
        share = auto / len(distractors)
        print(
            f"  wrongly auto-resolved {share:6.1%}  ({auto}/{len(distractors)})"
            f"  <- the damaging failure"
        )

    # ------------------------------------------------------------------
    # The number a price index actually lives on
    # ------------------------------------------------------------------
    # Top-1 asks "was the best guess right". A price monitor does not publish
    # best guesses. It publishes what it auto-resolved and sends the rest to a
    # human, so the question that matters is: of everything that goes out
    # WITHOUT being looked at, what share is correct — counting a distractor
    # that got auto-resolved as the error it is.
    #
    # That number can be driven as high as you like by refusing to auto-resolve
    # anything, which is what the platform does today. So it is meaningless on
    # its own and is reported here against the coverage it was bought at.
    if distractors:
        pos_conf = [
            (
                d.best.confidence if d.best else 0.0,
                d.best.canonical_item_code == r["expected"] if d.best else False,
            )
            for r, d in unseen_rows
        ]
        noise_conf = [d.best.confidence if d.best else 0.0 for d in noise]

        # Base-rate-free summary of the same thing. This test set is ~52%
        # distractors, far more than production would be, so the precision
        # column below is pessimistic in absolute terms. AUC is not: it asks
        # only whether a correct match tends to score above a distractor, and
        # 0.5 means the confidence carries no information at all.
        labels = [1] * len(pos_conf) + [0] * len(noise_conf)
        scores = [c for c, _ in pos_conf] + list(noise_conf)
        if len(set(labels)) == 2:
            order = sorted(range(len(scores)), key=lambda i: scores[i])
            ranks, i = [0.0] * len(scores), 0
            while i < len(order):
                j = i
                while j + 1 < len(order) and scores[order[j + 1]] == scores[order[i]]:
                    j += 1
                avg = (i + j) / 2 + 1
                for k in range(i, j + 1):
                    ranks[order[k]] = avg
                i = j + 1
            npos = sum(labels)
            nneg = len(labels) - npos
            auc = (sum(r for r, l in zip(ranks, labels) if l) - npos * (npos + 1) / 2) / (
                npos * nneg
            )
            print(
                f"\nCONFIDENCE AS A SIGNAL\n  AUC separating basket wordings from distractors: {auc:.3f}"
                "  (0.5 = no information)"
            )

        print("\nPRECISION OF WHAT GETS PUBLISHED UNREVIEWED, BY THRESHOLD")
        print("  threshold  coverage   published  correct  precision")
        best_at_999 = None
        for t in (0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95, 0.99):
            acc_pos = [ok for c, ok in pos_conf if c >= t]
            acc_noise = sum(1 for c in noise_conf if c >= t)
            published = len(acc_pos) + acc_noise
            correct = sum(acc_pos)
            if published == 0:
                print(f"  {t:>8.2f}   {0.0:7.1%}   {0:>9}  {0:>7}     n/a")
                continue
            precision = correct / published
            coverage = len(acc_pos) / len(pos_conf) if pos_conf else 0.0
            flag = ""
            if precision >= 0.999 and best_at_999 is None:
                best_at_999 = (t, coverage, published)
                flag = "  <- 99.9%"
            print(
                f"  {t:>8.2f}   {coverage:7.1%}   {published:>9}  {correct:>7}   "
                f"{precision:6.1%}{flag}"
            )
        if best_at_999:
            t, cov, n = best_at_999
            print(
                f"\n  99.9% precision is reached at threshold {t:.2f}, covering {cov:.1%} "
                f"of wordings ({n} published unreviewed)."
            )
        else:
            print(
                "\n  99.9% precision is NOT reached at any threshold on this set. The "
                "confidence score does not yet separate right from wrong sharply enough,"
                "\n  which is what fitting the calibrator is for."
            )

    print("\nMISSES ON UNSEEN TEXT")
    shown = 0
    for row, decision in unseen_rows:
        best = decision.best  # type: ignore[attr-defined]
        got = best.canonical_item_code if best else "(nothing)"
        if got != row["expected"]:
            conf = f"{best.confidence:.2f}" if best else "-"
            print(f"  {row['text'][:44]:<46} want {row['expected']:<22} got {got:<22} conf {conf}")
            shown += 1
            if shown >= 15:
                print("  …")
                break
    if shown == 0:
        print("  none")

    return 0


if __name__ == "__main__":
    sys.exit(main())
