# SPDX-License-Identifier: Apache-2.0
"""The normaliser, against every real string in the repository.

`normalise()` exists twice — here for the matcher, and in PHP for seeding and
for the Postgres trigram queries. The module docstring calls that duplication
dangerous and it is right: text normalised one way at index time and another at
query time simply fails to match, and nothing raises. A drift is silent.

That risk was guarded by 22 hand-written fixtures. This runs the same transform
over 3,887 strings people actually wrote — every catalogue variant, corpus
wording, distractor and evaluation row in the repository — against the output
both implementations were verified to agree on.

It proves consistency, not correctness: agreeing with PHP does not make either
side right. What it removes is the failure mode where they quietly stop agreeing.

**Measured against the fixtures it was meant to strengthen.** Each of the eight
character folds was disabled in turn and both suites run. The 22 fixtures caught
eight of eight. This caught seven: it misses alef wasla, because no real string
in the corpus contains one. The fixtures were written to cover every fold on
purpose; real text only contains what people write. Volume does not subsume
design, and this is a complement rather than a replacement.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from qeema_ml.matching.normalise import normalise

CONTRACT = Path(__file__).resolve().parents[2] / "contracts" / "text-normalisation-corpus.json"


@pytest.fixture(scope="module")
def cases() -> list[dict[str, str]]:
    return json.loads(CONTRACT.read_text(encoding="utf-8"))["cases"]


def test_the_corpus_contract_is_substantial(cases: list[dict[str, str]]) -> None:
    # Guards the guard: a truncated contract would make every assertion below
    # pass while testing almost nothing.
    assert len(cases) >= 3800


def test_every_real_string_normalises_as_the_contract_says(cases: list[dict[str, str]]) -> None:
    mismatches = [
        (c["input"], c["normalized"], normalise(c["input"]))
        for c in cases
        if normalise(c["input"]) != c["normalized"]
    ]

    assert mismatches == [], (
        f"{len(mismatches)} of {len(cases)} real strings normalise differently than the "
        f"contract PHP was verified against. First: {mismatches[:3]}"
    )


def test_normalising_twice_changes_nothing(cases: list[dict[str, str]]) -> None:
    # Idempotence over real text rather than over three examples. The matcher
    # normalises at index time and again at query time; if that were not
    # idempotent, a variant would stop matching itself.
    not_idempotent = [
        c["normalized"] for c in cases if normalise(c["normalized"]) != c["normalized"]
    ]

    assert not_idempotent == [], f"{len(not_idempotent)} strings change on a second pass"
