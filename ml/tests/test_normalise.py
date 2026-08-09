# SPDX-License-Identifier: Apache-2.0
"""Normalisation contract.

Driven by the same fixtures the PHP normaliser is tested against. If the two
implementations diverge, one of these suites fails — which is the entire reason
the fixtures are shared rather than duplicated.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from qeema_ml.matching.normalise import normalise, tokenise

CONTRACT_PATH = Path(__file__).resolve().parents[2] / "contracts" / "text-normalisation.json"


def contract_cases() -> list[dict[str, str]]:
    assert CONTRACT_PATH.exists(), f"Shared contract missing at {CONTRACT_PATH}"

    with CONTRACT_PATH.open(encoding="utf-8") as handle:
        return json.load(handle)["cases"]


CASES = contract_cases()


@pytest.mark.parametrize("case", CASES, ids=[c["name"] for c in CASES])
def test_satisfies_shared_contract(case: dict[str, str]) -> None:
    assert normalise(case["input"]) == case["expected"], case["name"]


def test_contract_covers_a_meaningful_number_of_cases() -> None:
    # Guards against the contract file being emptied and the suite above
    # passing vacuously.
    assert len(CASES) == 22


@pytest.mark.parametrize("case", CASES, ids=[c["name"] for c in CASES])
def test_is_idempotent(case: dict[str, str]) -> None:
    once = normalise(case["input"])

    assert normalise(once) == once


def test_makes_the_two_spellings_of_infant_formula_identical() -> None:
    assert normalise("حليب أطفال") == normalise("حليب اطفال")


def test_makes_arabic_indic_and_ascii_digits_match() -> None:
    assert normalise("حليب ٤٠٠ غرام") == normalise("حليب 400 غرام")


def test_folds_decomposed_and_composed_alef_identically() -> None:
    # A composed أ (U+0623) and a decomposed alef + hamza above must reach the
    # same result, or two visually identical strings would fail to match
    # depending on which keyboard produced them.
    composed = "أرز"
    decomposed = "أرز"

    assert normalise(composed) == normalise(decomposed)


def test_returns_empty_for_none() -> None:
    assert normalise(None) == ""


class TestTokenise:
    def test_splits_a_normalised_string(self) -> None:
        assert tokenise("حليب  أطفال ٤٠٠") == ["حليب", "اطفال", "400"]

    def test_returns_nothing_for_empty_input(self) -> None:
        assert tokenise("") == []
        assert tokenise(None) == []
        assert tokenise("   ") == []
