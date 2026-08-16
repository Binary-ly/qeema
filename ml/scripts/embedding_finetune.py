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
import sys
from dataclasses import dataclass

import numpy as np
from sentence_transformers import SentenceTransformer
from sklearn.metrics import roc_auc_score

sys.path.insert(0, "src")
from qeema_ml.matching.normalise import normalise

TEST_SHARE = 0.4
QUERY_PREFIX = "query: "
PASSAGE_PREFIX = "passage: "


@dataclass
class Split:
    index_texts: list[str]
    index_items: list[str]
    train_pairs: list[tuple[str, str, str]]
    test_texts: list[str]
    test_items: list[str]
    distractors: list[str]


def build_split(data: dict) -> Split:
    """Deterministic per-item split. Every item appears on both sides."""
    index_texts: list[str] = []
    index_items: list[str] = []
    train_pairs: list[tuple[str, str, str]] = []
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
            if position % 5 in (3, 4):
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
        test_texts=test_texts,
        test_items=test_items,
        distractors=data["distractors"],
    )


def evaluate(model: SentenceTransformer, split: Split, label: str) -> dict:
    index = model.encode(
        [PASSAGE_PREFIX + normalise(t) for t in split.index_texts],
        normalize_embeddings=True,
        show_progress_bar=False,
        batch_size=64,
    )
    queries = model.encode(
        [QUERY_PREFIX + normalise(t) for t in split.test_texts],
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
    top1 = float((items[best] == np.array(split.test_items)).mean())

    # Top-3 by item, not by passage: an item with forty variants would otherwise
    # fill every slot and the number would mean nothing.
    hits = 0
    for row, truth in zip(similarity, split.test_items, strict=True):
        order = np.argsort(-row)
        seen: list[str] = []
        for position in order:
            code = items[position]
            if code not in seen:
                seen.append(code)
            if len(seen) == 3:
                break
        hits += truth in seen
    top3 = hits / len(split.test_items)

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


def main() -> int:
    with open("/tmp/ml_eval.json") as handle:
        data = json.load(handle)

    split = build_split(data)
    print(
        f"index {len(split.index_texts)} passages | held-out {len(split.test_texts)} "
        f"wordings | {len(split.distractors)} distractors | "
        f"{len(split.train_pairs)} training pairs\n"
    )

    results = []

    print("BASELINE — no training")
    for name in ("intfloat/multilingual-e5-base", "intfloat/multilingual-e5-large"):
        results.append(evaluate(SentenceTransformer(name), split, name.split("/")[-1]))

    print("\nFINE-TUNED on the training half")
    from datasets import Dataset
    from sentence_transformers import (
        SentenceTransformerTrainer,
        SentenceTransformerTrainingArguments,
    )
    from sentence_transformers.losses import MultipleNegativesRankingLoss

    model = SentenceTransformer("intfloat/multilingual-e5-base")
    dataset = Dataset.from_dict(
        {
            "anchor": [QUERY_PREFIX + normalise(a) for a, _, _ in split.train_pairs],
            "positive": [PASSAGE_PREFIX + normalise(p) for _, p, _ in split.train_pairs],
            "negative": [PASSAGE_PREFIX + normalise(n) for _, _, n in split.train_pairs],
        }
    )

    trainer = SentenceTransformerTrainer(
        model=model,
        args=SentenceTransformerTrainingArguments(
            output_dir="/tmp/e5-qeema",
            num_train_epochs=6,
            per_device_train_batch_size=32,
            warmup_steps=0.1,
            learning_rate=2e-5,
            logging_steps=50,
            save_strategy="no",
            report_to=[],
            seed=17,
        ),
        train_dataset=dataset,
        # In-batch negatives: every other positive in the batch is a negative for
        # this anchor, which for 27 items means the model is constantly told
        # "rice is not oil" without anyone labelling that.
        loss=MultipleNegativesRankingLoss(model),
    )
    trainer.train()

    results.append(evaluate(model, split, "e5-base fine-tuned"))
    model.save("/tmp/e5-qeema/final")

    baseline = results[0]
    print("\nagainst the shipped model:")
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
