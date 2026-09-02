# SPDX-License-Identifier: Apache-2.0
"""The evaluation set and the catalogue must not contradict each other.

A row in ``ml/data/real-text/*.json`` with ``expected: null`` asserts *no
catalogue item is the right answer*. Listing a wording under
``canonical_items[].variants`` in the country file asserts *this item is the
right answer*. A string in both places asserts both at once, and the matcher
believes the catalogue: an exact match after normalisation short-circuits
before any model runs, so the row auto-resolves at 0.99 and the evaluation
scores it as the failure it calls damaging — a real product that belongs to no
basket item, published with no human in the loop.

Four such strings were live when this was written, and what they corrupted was
the safety claim this project quotes: ``docs/assessment.md`` published "0 of
485 wrongly auto-resolved" while a re-run measured 4 of 829, every one of them
an exact match rather than a model error. The equivalent guard already existed
one directory away — ``qeema:corpus:promote`` refuses to promote a wording that
is also listed as a distractor — but it covers ``countries/corpus/*.json`` and
nothing applied the same rule to the evaluation set.

The rule is the promote command's own: a string the catalogue and the
evaluation disagree about is a contradiction, and picking a side silently is a
guess. This fails the build instead of quietly moving a published number.

Country literals are fine here — ``**/tests/**`` is exempt from the C3 grep —
and the default mirrors ``ml/scripts/real_text_evaluation.py`` so the guard and
the measurement always read the same catalogue.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

import pytest
import yaml

from qeema_ml.matching.normalise import normalise

REPO = Path(__file__).resolve().parents[2]
REAL_TEXT = REPO / "ml" / "data" / "real-text"
COUNTRY = Path(os.environ.get("QEEMA_EVAL_COUNTRY", str(REPO / "countries" / "ly.yaml")))


@pytest.fixture(scope="module")
def rows() -> list[dict]:
    collected: list[dict] = []
    for path in sorted(REAL_TEXT.glob("*.json")):
        for row in json.loads(path.read_text(encoding="utf-8")):
            collected.append({**row, "_file": path.name})
    return collected


@pytest.fixture(scope="module")
def catalogue() -> dict[str, str]:
    """Normalised wording -> item code, exactly as the matcher's exact tier keys it.

    Includes ``name_en`` and ``name_local`` as well as the variants: the
    short-circuit is built over all three, so a distractor colliding with an
    item's own name auto-resolves just as surely as one colliding with a
    variant.
    """
    config = yaml.safe_load(COUNTRY.read_text(encoding="utf-8"))
    exact: dict[str, str] = {}
    for item in config["canonical_items"]:
        texts = [item["name_en"]]
        if item.get("name_local"):
            texts.append(item["name_local"])
        texts.extend(str(v) for v in (item.get("variants") or []))
        for text in texts:
            key = normalise(text)
            if key:
                exact.setdefault(key, item["code"])
    return exact


def test_the_evaluation_set_is_substantial(rows: list[dict]) -> None:
    # Guards the guard: an empty or truncated set would make every assertion
    # below pass while checking nothing at all.
    assert len(rows) >= 1000
    assert sum(1 for r in rows if not r["expected"]) >= 500


def test_no_distractor_is_also_a_catalogue_wording(
    rows: list[dict], catalogue: dict[str, str]
) -> None:
    """A string cannot both match nothing and be a variant of something."""
    collisions = [
        (r["_file"], r["text"], catalogue[normalise(r["text"])])
        for r in rows
        if not r["expected"] and normalise(r["text"]) in catalogue
    ]

    assert collisions == [], (
        "These strings are labelled as matching no basket item and are also "
        "catalogue wordings, so the matcher auto-resolves them on an exact "
        "match and the evaluation counts each as a wrongly auto-resolved "
        "product. Decide which side is wrong — remove the variant, or give the "
        "row the item code it deserves — rather than leaving both:\n"
        + "\n".join(f"  {f}: {t!r} is a wording of {code}" for f, t, code in collisions)
    )


def test_every_expected_code_exists_in_the_country_file(rows: list[dict]) -> None:
    """A typo'd item code silently becomes a permanent miss."""
    config = yaml.safe_load(COUNTRY.read_text(encoding="utf-8"))
    known = {item["code"] for item in config["canonical_items"]}
    unknown = sorted({r["expected"] for r in rows if r["expected"] and r["expected"] not in known})

    assert unknown == [], (
        f"{COUNTRY.name} defines no such item code, so every row labelled with "
        f"one can never be scored correct: {unknown}"
    )


def test_no_wording_is_labelled_two_different_ways(rows: list[dict]) -> None:
    """The same normalised string cannot be two different items."""
    seen: dict[str, set[str]] = {}
    for row in rows:
        if row["expected"]:
            seen.setdefault(normalise(row["text"]), set()).add(row["expected"])

    conflicts = {text: codes for text, codes in seen.items() if len(codes) > 1}

    assert conflicts == {}, (
        "These wordings are labelled as more than one item, so one of the "
        "labels scores as an error whatever the matcher does: "
        f"{ {t: sorted(c) for t, c in conflicts.items()} }"
    )
