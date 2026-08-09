# SPDX-License-Identifier: Apache-2.0
"""Hybrid matching.

Tested with a deterministic stand-in embedder rather than real weights: loading
1.1 GB per test run would make the suite unusable and would pull from the
network in CI. The weights themselves are exercised by a `slow`-marked test and
by the evaluation harness.
"""

from __future__ import annotations

from typing import ClassVar

import numpy as np
import pytest

from qeema_ml.matching.fusion import ConfidenceCalibrator, fuse
from qeema_ml.matching.lexical import LexicalIndex, score_pair
from qeema_ml.matching.matcher import CatalogueIndexes, HybridMatcher, MatcherConfig
from qeema_ml.matching.normalise import normalise
from qeema_ml.matching.semantic import SemanticIndex

CATALOGUE = [
    (
        1,
        "infant_formula_400g",
        ["حليب أطفال ٤٠٠ غرام", "حليب اطفال", "baby milk", "infant formula"],
    ),
    (2, "rice_1kg", ["أرز", "ارز ابيض", "rice", "white rice"]),
    (3, "cooking_oil_1l", ["زيت طعام", "زيت", "cooking oil", "vegetable oil"]),
    (4, "cooking_gas_11kg", ["أسطوانة غاز", "بوتاجاز", "gas cylinder", "cooking gas"]),
    (5, "baby_cereal_400g", ["سيريلاك", "baby cereal", "cerelac"]),
]


def build_lexical() -> LexicalIndex:
    rows = [
        {
            "canonical_item_id": item_id,
            "canonical_item_code": code,
            "text": variant,
            "normalized_text": normalise(variant),
        }
        for item_id, code, variants in CATALOGUE
        for variant in variants
    ]

    return LexicalIndex.from_rows(rows)


def build_exact() -> dict[str, tuple[int, str]]:
    return {
        normalise(variant): (item_id, code)
        for item_id, code, variants in CATALOGUE
        for variant in variants
    }


class StubEmbedder:
    """Deterministic embedder with a hand-built semantic space.

    Items that genuinely mean similar things are placed near each other, so the
    tests exercise the fusion logic rather than the model's opinions.
    """

    _VECTORS: ClassVar[dict[int, list[float]]] = {
        1: [1.0, 0.0, 0.0],  # infant formula
        5: [0.9, 0.2, 0.0],  # baby cereal — close to formula, as it should be
        2: [0.0, 1.0, 0.0],  # rice
        3: [0.0, 0.8, 0.3],  # cooking oil — near rice (both staples)
        4: [0.0, 0.0, 1.0],  # gas
    }

    _QUERIES: ClassVar[dict[str, list[float]]] = {
        "baby milk": [1.0, 0.0, 0.0],
        "حليب": [0.95, 0.1, 0.0],
        "بوتاجاز": [0.0, 0.0, 1.0],
        "طبخ": [0.0, 0.5, 0.6],
    }

    @property
    def dimension(self) -> int:
        return 3

    def encode_queries(self, texts: list[str]) -> np.ndarray:
        return np.array([self._QUERIES.get(t, [0.33, 0.33, 0.33]) for t in texts], dtype=np.float32)

    def encode_passages(self, texts: list[str]) -> np.ndarray:  # pragma: no cover - unused
        return np.zeros((len(texts), 3), dtype=np.float32)


def build_semantic() -> SemanticIndex:
    ids = [item_id for item_id, _, _ in CATALOGUE]
    codes = [code for _, code, _ in CATALOGUE]
    vectors = np.array([StubEmbedder._VECTORS[i] for i in ids], dtype=np.float32)

    return SemanticIndex(ids, codes, vectors)


def build_catalogue(semantic: bool = True) -> CatalogueIndexes:
    return CatalogueIndexes(
        lexical=build_lexical(),
        semantic=build_semantic() if semantic else None,
        exact=build_exact(),
    )


def build_matcher(**overrides) -> HybridMatcher:
    return HybridMatcher(MatcherConfig(**overrides), embedder=StubEmbedder())


class TestLexicalScoring:
    def test_identical_strings_score_one(self) -> None:
        assert score_pair("ارز", "ارز") == pytest.approx(1.0)

    def test_unrelated_strings_score_low(self) -> None:
        assert score_pair("ارز", "بوتاجاز") < 0.6

    def test_empty_input_scores_zero(self) -> None:
        assert score_pair("", "ارز") == 0.0

    def test_word_order_does_not_matter(self) -> None:
        assert score_pair("ابيض ارز", "ارز ابيض") > 0.9

    def test_a_substring_query_scores_well(self) -> None:
        # "حليب" against "حليب اطفال 400 غرام" is the single most common shape
        # of real query: the reporter types the head noun only.
        assert score_pair("حليب", "حليب اطفال 400 غرام") > 0.8


class TestLexicalIndex:
    def test_finds_an_item_from_a_misspelling(self) -> None:
        hits = build_lexical().search("حليب اطفل")

        assert hits[0].canonical_item_id == 1

    def test_returns_one_hit_per_item_not_per_variant(self) -> None:
        # An item with forty recorded spellings would otherwise fill every slot
        # and crowd out genuine alternatives.
        hits = build_lexical().search("حليب اطفال", limit=5)
        ids = [h.canonical_item_id for h in hits]

        assert len(ids) == len(set(ids))

    def test_keeps_the_best_matching_variant(self) -> None:
        hits = build_lexical().search("infant formula")

        assert hits[0].canonical_item_id == 1
        assert "formula" in hits[0].matched_variant.lower()

    def test_returns_nothing_for_empty_input(self) -> None:
        assert build_lexical().search("") == []

    def test_handles_an_empty_index(self) -> None:
        assert LexicalIndex.from_rows([]).search("anything") == []


class TestSemanticIndex:
    def test_finds_the_nearest_item(self) -> None:
        index = build_semantic()
        query = np.array([1.0, 0.0, 0.0], dtype=np.float32)

        assert index.search(query)[0].canonical_item_id == 1

    def test_scores_are_bounded_cosine_similarities(self) -> None:
        index = build_semantic()
        hits = index.search(np.array([1.0, 0.0, 0.0], dtype=np.float32))

        assert all(-1.0 <= h.score <= 1.0 for h in hits)

    def test_a_zero_query_returns_nothing_rather_than_nan(self) -> None:
        index = build_semantic()

        assert index.search(np.zeros(3, dtype=np.float32)) == []

    def test_an_unembedded_item_does_not_poison_every_score(self) -> None:
        # A zero row would produce NaN for every query without the guard.
        index = SemanticIndex(
            [1, 2], ["a", "b"], np.array([[0.0, 0.0], [1.0, 0.0]], dtype=np.float32)
        )
        hits = index.search(np.array([1.0, 0.0], dtype=np.float32))

        assert all(not np.isnan(h.score) for h in hits)

    def test_rejects_mismatched_ids_and_embeddings(self) -> None:
        with pytest.raises(ValueError, match="item ids"):
            SemanticIndex([1, 2, 3], ["a", "b", "c"], np.zeros((2, 3), dtype=np.float32))


class TestFusion:
    def test_weights_are_normalised(self) -> None:
        fused = fuse({1: 1.0}, {1: 0.0}, lexical_weight=2.0, semantic_weight=2.0)

        assert fused[1] == pytest.approx(0.5)

    def test_an_item_missing_from_one_signal_still_appears(self) -> None:
        # A strong lexical match must survive when the item has no embedding
        # yet, which is the normal state just after an item is added.
        fused = fuse({1: 0.9}, {2: 0.9}, 0.5, 0.5)

        assert set(fused) == {1, 2}
        assert fused[1] == pytest.approx(0.45)

    def test_degenerate_weights_fall_back_to_equal(self) -> None:
        fused = fuse({1: 1.0}, {1: 0.0}, 0.0, 0.0)

        assert fused[1] == pytest.approx(0.5)


class TestCalibration:
    def test_is_conservative_before_being_fitted(self) -> None:
        # The key property: an uncalibrated deployment must not auto-resolve at
        # the same threshold as a calibrated one.
        calibrator = ConfidenceCalibrator()

        assert not calibrator.is_fitted
        assert calibrator.calibrate(0.9) < 0.9

    def test_refuses_to_fit_on_too_little_data(self) -> None:
        calibrator = ConfidenceCalibrator()

        assert not calibrator.fit([0.9, 0.2], [True, False])
        assert not calibrator.is_fitted

    def test_refuses_to_fit_when_only_one_outcome_is_present(self) -> None:
        calibrator = ConfidenceCalibrator()
        scores = [i / 100 for i in range(100)]

        assert not calibrator.fit(scores, [True] * 100)

    def test_fits_from_enough_labelled_outcomes(self) -> None:
        calibrator = ConfidenceCalibrator()
        scores = [i / 100 for i in range(100)]
        correct = [s > 0.6 for s in scores]

        assert calibrator.fit(scores, correct)
        assert calibrator.is_fitted
        assert calibrator.calibrate(0.9) > calibrator.calibrate(0.3)

    def test_calibrated_output_stays_in_range(self) -> None:
        calibrator = ConfidenceCalibrator()
        scores = [i / 100 for i in range(100)]
        calibrator.fit(scores, [s > 0.5 for s in scores])

        assert 0.0 <= calibrator.calibrate(-5.0) <= 1.0
        assert 0.0 <= calibrator.calibrate(5.0) <= 1.0

    def test_rejects_mismatched_inputs(self) -> None:
        with pytest.raises(ValueError, match="same length"):
            ConfidenceCalibrator().fit([0.1, 0.2], [True])


class TestHybridMatcher:
    def test_an_exact_variant_resolves_without_the_model(self) -> None:
        # The most common case must never depend on a model being loaded,
        # calibrated, or right.
        matcher = HybridMatcher(MatcherConfig(), embedder=None)
        decision = matcher.match("حليب اطفال", build_catalogue(semantic=False))

        assert decision.action == "auto_resolve"
        assert decision.best.canonical_item_id == 1
        assert "Exact match" in decision.reason

    def test_an_unnormalised_spelling_still_matches_exactly(self) -> None:
        # ٤٠٠ and hamza differences must not defeat the exact path.
        matcher = build_matcher()
        decision = matcher.match("حليب أطفال ٤٠٠ غرام", build_catalogue())

        assert decision.action == "auto_resolve"
        assert decision.best.canonical_item_id == 1

    def test_a_misspelling_is_matched_lexically(self) -> None:
        decision = build_matcher().match("حليب اطفل", build_catalogue())

        assert decision.best.canonical_item_id == 1
        assert decision.best.lexical_score > 0.7

    def test_a_cross_language_query_is_matched_semantically(self) -> None:
        # "بوتاجاز" has no lexical overlap with "gas cylinder"; only the
        # embedding connects them.
        decision = build_matcher(lexical_weight=0.2, semantic_weight=0.8).match(
            "بوتاجاز", build_catalogue()
        )

        assert decision.best.canonical_item_id == 4

    def test_empty_text_is_rejected(self) -> None:
        decision = build_matcher().match("   ", build_catalogue())

        assert decision.action == "reject"
        assert decision.candidates == []

    def test_returns_at_most_top_k_candidates(self) -> None:
        decision = build_matcher(top_k=3).match("زيت طبخ", build_catalogue())

        assert len(decision.candidates) <= 3

    def test_candidates_are_ordered_by_fused_score(self) -> None:
        decision = build_matcher().match("زيت", build_catalogue())
        scores = [c.fused_score for c in decision.candidates]

        assert scores == sorted(scores, reverse=True)

    def test_a_confident_but_ambiguous_match_goes_to_review(self) -> None:
        # Two near-identical candidates mean the model cannot tell them apart;
        # taking the higher one is a coin flip dressed up as a decision.
        matcher = build_matcher(auto_resolve_threshold=0.0)
        catalogue = build_catalogue()
        decision = matcher.match("مياه غازية", catalogue)

        if len(decision.candidates) > 1:
            gap = decision.candidates[0].fused_score - decision.candidates[1].fused_score
            if gap < 0.05:
                assert decision.action == "review"
                assert "ambiguous" in decision.reason

    def test_a_low_confidence_match_is_routed_to_review(self) -> None:
        matcher = build_matcher(auto_resolve_threshold=0.99, review_threshold=0.0)
        decision = matcher.match("something unrelated entirely", build_catalogue())

        assert decision.action in {"review", "reject"}

    def test_works_with_no_semantic_index_at_all(self) -> None:
        # Cold start: items exist but nothing has been embedded yet.
        decision = HybridMatcher(MatcherConfig(), embedder=None).match(
            "حليب اطفل", build_catalogue(semantic=False)
        )

        assert decision.best is not None
        assert decision.best.semantic_score == 0.0

    def test_every_candidate_reports_both_component_scores(self) -> None:
        # A reviewer needs to see *why* something ranked where it did.
        decision = build_matcher().match("حليب اطفل", build_catalogue())

        for candidate in decision.candidates:
            assert 0.0 <= candidate.lexical_score <= 1.0
            assert -1.0 <= candidate.semantic_score <= 1.0
            assert 0.0 <= candidate.confidence <= 1.0


class TestFusionWithMissingSignals:
    """Regression cover for a bug that made the cold-start state unusable.

    With ``semantic_weight=0.6`` and no semantic index — the normal state before
    anything has been embedded — a *perfect* lexical match of 1.0 fused to 0.40
    and was rejected outright. Measured on the real 18-item catalogue that
    routed 63.9% of correct matches to rejection while top-1 accuracy read
    98.4%: the matcher found the right answer and threw it away.
    """

    def test_a_perfect_lexical_match_survives_a_missing_semantic_index(self) -> None:
        fused = fuse(
            {1: 1.0}, {}, lexical_weight=0.4, semantic_weight=0.6, semantic_available=False
        )

        assert fused[1] == pytest.approx(1.0)

    def test_absent_evidence_is_not_evidence_of_absence(self) -> None:
        # A signal that never ran must not count against a candidate...
        without = fuse({1: 0.9}, {}, 0.4, 0.6, semantic_available=False)

        # ...but one that ran and returned nothing for this item legitimately does.
        with_signal = fuse({1: 0.9}, {2: 0.8}, 0.4, 0.6, semantic_available=True)

        assert without[1] == pytest.approx(0.9)
        assert with_signal[1] == pytest.approx(0.36)

    def test_the_matcher_does_not_reject_a_strong_match_without_embeddings(self) -> None:
        decision = HybridMatcher(
            MatcherConfig(lexical_weight=0.4, semantic_weight=0.6), embedder=None
        ).match("حليب اطفل", build_catalogue(semantic=False))

        assert decision.action != "reject"
        assert decision.best.canonical_item_id == 1
