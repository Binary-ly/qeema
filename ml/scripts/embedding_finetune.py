# SPDX-License-Identifier: Apache-2.0
"""Does fine-tuning the embedder on this domain actually help, and by how much?

**Why the embedder is the suspect.** Everything measured so far points at it. The
semantic score alone separates correct from wrong at AUC 0.607 — barely above
chance. On the near-neighbour probes it ranked the *wrong* answer above the right
one twice out of four. And `multilingual-e5-base` is a general web-text model
being asked about Libyan dialect and franco-arabe: `9arora gaz`, `دحي وطني`,
`بمبلة`. That is not what it was trained on.

**The protocol, fixed before any training runs.**

Wordings are split per item, deterministically, 60/40. The *index* holds the
catalogue's hand-written vocabulary plus the training wordings; the *queries* are
the held-out 40%, which appear nowhere in the index. Top-1 is whether the nearest
indexed passage belongs to the right item.

This measures the embedder alone — no lexical scorer, no fusion, no exact-match
short-circuit. That is deliberate: it isolates the component under test, and it
sidesteps the wildcard-subset leak that contaminated the earlier verifier
experiment entirely, because token overlap plays no part here.

**Two numbers, not one.** Retrieval accuracy is easy to buy by making everything
look like everything else, which would wreck the platform's ability to refuse.
So distractor separation is measured alongside it: the gap between how close a
held-out *correct* wording sits to its item and how close a *distractor* sits to
whatever it is nearest. A model that improves top-1 while closing that gap has
not improved anything.
"""

from __future__ import annotations

import json
import os
import sys
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import yaml
from sentence_transformers import SentenceTransformer
from sklearn.metrics import roc_auc_score

sys.path.insert(0, "src")
from qeema_ml.matching.normalise import normalise

REPO = Path(__file__).resolve().parents[2]
TEST_SHARE = 0.4
QUERY_PREFIX = "query: "
PASSAGE_PREFIX = "passage: "


@dataclass
class Split:
    index_texts: list[str]
    index_items: list[str]
    train_pairs: list[tuple[str, str, str]]
    val_texts: list[str]
    val_items: list[str]
    test_texts: list[str]
    test_items: list[str]
    distractors: list[str]


def build_split(data: dict) -> Split:
    """Deterministic per-item split. Every item appears on both sides."""
    index_texts: list[str] = []
    index_items: list[str] = []
    train_pairs: list[tuple[str, str, str]] = []
    val_texts: list[str] = []
    val_items: list[str] = []
    test_texts: list[str] = []
    test_items: list[str] = []

    for code, variants in data["catalogue"].items():
        for text in variants:
            index_texts.append(text)
            index_items.append(code)

    for code, wordings in data["corpus"].items():
        if code not in data["catalogue"]:
            continue

        # Positional, so the run reproduces exactly. Every fourth and fifth
        # wording is held out.
        for position, wording in enumerate(wordings):
            # Held out twice over. Configurations are chosen on validation and
            # the winner is reported once on test. Tuning against the number you
            # then publish is how a search invents a result.
            if position % 5 == 3:
                val_texts.append(wording)
                val_items.append(code)
            elif position % 5 == 4:
                test_texts.append(wording)
                test_items.append(code)
            else:
                index_texts.append(wording)
                index_items.append(code)
                # One positive per wording — the catalogue's own primary name for
                # the item. Pairing each wording against every variant instead
                # produced 2,756 pairs from 444 wordings, six times the training
                # cost for the same signal.
                #
                # The negative is a distractor, and it is the point of this run.
                # In-batch negatives teach "rice is not oil", which is retrieval
                # between items; nothing in that teaches "a car battery is not
                # any of these". Separation is the number that decides whether
                # the platform can ever auto-resolve or refuse on a model score,
                # and it was +0.023 with no training at all.
                positive = data["catalogue"][code][0]
                negative = data["distractors"][len(train_pairs) % len(data["distractors"])]
                train_pairs.append((wording, positive, negative))

    return Split(
        index_texts=index_texts,
        index_items=index_items,
        train_pairs=train_pairs,
        val_texts=val_texts,
        val_items=val_items,
        test_texts=test_texts,
        test_items=test_items,
        distractors=data["distractors"],
    )


def evaluate(model: SentenceTransformer, split: Split, label: str, on: str = "test") -> dict:
    texts = split.val_texts if on == "val" else split.test_texts
    truth = split.val_items if on == "val" else split.test_items
    index = model.encode(
        [PASSAGE_PREFIX + normalise(t) for t in split.index_texts],
        normalize_embeddings=True,
        show_progress_bar=False,
        batch_size=64,
    )
    queries = model.encode(
        [QUERY_PREFIX + normalise(t) for t in texts],
        normalize_embeddings=True,
        show_progress_bar=False,
        batch_size=64,
    )
    noise = model.encode(
        [QUERY_PREFIX + normalise(t) for t in split.distractors],
        normalize_embeddings=True,
        show_progress_bar=False,
        batch_size=64,
    )

    items = np.array(split.index_items)

    similarity = queries @ index.T
    best = similarity.argmax(axis=1)
    top1 = float((items[best] == np.array(truth)).mean())

    # Top-3 by item, not by passage: an item with forty variants would otherwise
    # fill every slot and the number would mean nothing.
    hits = 0
    for row, want in zip(similarity, truth, strict=True):
        order = np.argsort(-row)
        seen: list[str] = []
        for position in order:
            code = items[position]
            if code not in seen:
                seen.append(code)
            if len(seen) == 3:
                break
        hits += want in seen
    top3 = hits / len(texts)

    correct_similarity = similarity.max(axis=1)
    noise_similarity = (noise @ index.T).max(axis=1)
    gap = float(correct_similarity.mean() - noise_similarity.mean())

    # Can any threshold on nearest-similarity tell a tracked product from noise?
    # This is the number that decides whether the platform can ever auto-resolve
    # or refuse on a model score, and a difference of means hides it.
    separation = float(
        roc_auc_score(
            [1] * len(correct_similarity) + [0] * len(noise_similarity),
            np.concatenate([correct_similarity, noise_similarity]),
        )
    )

    print(
        f"  {label:<30} top-1 {top1:6.1%}  top-3 {top3:6.1%}  "
        f"gap {gap:+.3f}  separation AUC {separation:.3f}"
    )

    return {"label": label, "top1": top1, "top3": top3, "gap": gap, "separation": separation}


def load_dataset() -> dict:
    """Build the experiment's input from the repository, not from a scratch file.

    This used to read /tmp/ml_eval.json. That file existed on the machine where
    the 80.3 -> 87.1 figure was produced, so the script ran there and nowhere
    else; by the time anyone looked it was a three-day-old snapshot carrying 712
    wordings against the corpus's 796. A number a reviewer cannot reproduce is
    not a result, and this one is quoted to a funder.

    An explicit path argument still wins, so a harvested corpus can be swapped in
    to ask whether real wordings beat generated ones.
    """
    if len(sys.argv) > 1:
        return json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))

    config = yaml.safe_load((REPO / "countries" / "ly.yaml").read_text(encoding="utf-8"))
    catalogue = {
        item["code"]: [item["name_en"], *([item["name_local"]] if item.get("name_local") else [])]
        + [str(v) for v in (item.get("variants") or [])]
        for item in config["canonical_items"]
    }

    corpus = json.loads((REPO / "countries" / "corpus" / "ly.json").read_text(encoding="utf-8"))

    return {
        "catalogue": catalogue,
        "corpus": corpus["items"],
        "distractors": corpus["distractors"],
    }


def main() -> int:
    data = load_dataset()

    split = build_split(data)
    print(
        f"index {len(split.index_texts)} passages | held-out {len(split.test_texts)} "
        f"wordings | {len(split.distractors)} distractors | "
        f"{len(split.train_pairs)} training pairs\n"
    )

    results = []

    # e5-large is skipped by default. Its baseline is already recorded — top-1
    # 85.4%, separation 0.728 — and loading 2.2 GB again to re-derive a known
    # number is the sort of thing that cooks a laptop for nothing.
    baselines = ["intfloat/multilingual-e5-base"]
    if os.environ.get("WITH_LARGE") == "1":
        baselines.append("intfloat/multilingual-e5-large")

    print("BASELINE — no training")
    for name in baselines:
        results.append(evaluate(SentenceTransformer(name), split, name.split("/")[-1]))

    from datasets import Dataset
    from sentence_transformers import (
        SentenceTransformerTrainer,
        SentenceTransformerTrainingArguments,
    )
    from sentence_transformers.losses import MultipleNegativesRankingLoss

    dataset = Dataset.from_dict(
        {
            "anchor": [QUERY_PREFIX + normalise(a) for a, _, _ in split.train_pairs],
            "positive": [PASSAGE_PREFIX + normalise(p) for _, p, _ in split.train_pairs],
            "negative": [PASSAGE_PREFIX + normalise(n) for _, _, n in split.train_pairs],
        }
    )

    def train(batch: int, epochs: int, lr: float) -> SentenceTransformer:
        model = SentenceTransformer("intfloat/multilingual-e5-base")
        SentenceTransformerTrainer(
            model=model,
            args=SentenceTransformerTrainingArguments(
                output_dir="/tmp/e5-qeema",
                num_train_epochs=epochs,
                per_device_train_batch_size=batch,
                warmup_steps=0.1,
                learning_rate=lr,
                logging_steps=1000,
                save_strategy="no",
                report_to=[],
                seed=17,
                disable_tqdm=True,
            ),
            train_dataset=dataset,
            # In-batch negatives are the reason batch size is the first thing
            # worth sweeping: every other positive in the batch is a negative
            # for this anchor, so a bigger batch is a harder, more informative
            # task at no extra data cost.
            loss=MultipleNegativesRankingLoss(model),
        ).train()
        return model

    print("\nSWEEP — scored on VALIDATION only, so the test half stays unseen")
    # One model per grid point. The default grid is a single configuration and
    # FULL_SWEEP=1 makes it four — but even the single one is 12 epochs over a
    # 278M-parameter model, which on a Mac CPU takes hours and pushes the load
    # average past 20. Run this on a GPU; ml/notebooks/finetune_colab.ipynb does
    # it on Colab's free tier in a few minutes.
    # Batch size is the first thing worth sweeping because in-batch negatives
    # scale with it. A four-configuration sweep found (64, 12, 3e-5) on
    # validation; the grid is kept to that one by default so re-running confirms
    # the winner on test rather than re-deriving the search every time.
    grid = [(64, 12, 3e-5)]
    if os.environ.get("FULL_SWEEP") == "1":
        grid = [(32, 4, 2e-5), (64, 12, 3e-5), (64, 30, 3e-5), (128, 30, 5e-5)]
    trained: dict[str, SentenceTransformer] = {}
    scored = []

    for batch, epochs, lr in grid:
        label = f"batch {batch}, {epochs} epochs, lr {lr:g}"
        model = train(batch, epochs, lr)
        trained[label] = model
        scored.append(evaluate(model, split, label, on="val"))

    print("\n  baseline on validation, for reference:")
    base_val = evaluate(
        SentenceTransformer("intfloat/multilingual-e5-base"), split, "e5-base", on="val"
    )

    # Separation is what this is for. Retrieval must not collapse to buy it, so
    # a configuration that loses more than five points of top-1 is refused
    # outright rather than allowed to win on the headline number.
    eligible = [r for r in scored if r["top1"] >= base_val["top1"] - 0.05]
    winner = max(eligible or scored, key=lambda r: r["separation"])
    print(f"\n  chosen on validation: {winner['label']}")

    print("\nTEST — reported once, on the half nothing was chosen against")
    results.append(evaluate(trained[winner["label"]], split, "e5-base fine-tuned"))
    out = os.environ.get("QEEMA_FINETUNE_OUT", "/tmp/e5-qeema/final")
    trained[winner["label"]].save(out)
    print(f"  saved the winning model to {out}")

    baseline = results[0]
    print("\nagainst the shipped model, on test:")
    for row in results[1:]:
        print(
            f"  {row['label']:<30} top-1 {row['top1'] - baseline['top1']:+6.1%}  "
            f"top-3 {row['top3'] - baseline['top3']:+6.1%}  "
            f"gap {row['gap'] - baseline['gap']:+.3f}  "
            f"sep {row['separation'] - baseline['separation']:+.3f}"
        )

    with open("/tmp/finetune_results.json", "w") as handle:
        json.dump(results, handle, indent=2)

    return 0


if __name__ == "__main__":
    sys.exit(main())
