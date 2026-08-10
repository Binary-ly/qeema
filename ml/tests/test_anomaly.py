# SPDX-License-Identifier: Apache-2.0
"""Anomaly detection.

The layers are tested for what each is *for*, and — as importantly — for
staying quiet when they have nothing to say. A layer that scores in the absence
of context accumulates false positives, and every false positive is a
reviewer's minute spent on nothing.
"""

from __future__ import annotations

import pytest

from qeema_ml.anomaly.detector import (
    VERDICT_CLEAN,
    VERDICT_REJECTED,
    AnomalyDetector,
    DetectorConfig,
    Observation,
    build_features,
)
from qeema_ml.anomaly.layers import hard_bounds, robust_outlier, roundness
from qeema_ml.anomaly.reporter_bias import detect as detect_bias
from qeema_ml.anomaly.reporter_bias import suspicious_reporter_ids


class TestHardBounds:
    def test_stays_silent_without_a_reference(self) -> None:
        # A cold-start item must not have everything rejected before any
        # history exists.
        assert hard_bounds(100.0, None, 4.0).score == 0.0

    def test_flags_a_decimal_slip_upwards(self) -> None:
        result = hard_bounds(650.0, 6.5, 4.0)

        assert result.score == 1.0
        assert "100.0x" in result.reasons[0].message

    def test_flags_a_unit_confusion_downwards(self) -> None:
        result = hard_bounds(0.0065, 6.5, 4.0)

        assert result.score == 1.0
        assert result.reasons[0].code == "hard_bounds_low"

    def test_permits_a_genuine_supply_shock(self) -> None:
        # Prices in these economies really do jump 40% in a week. A detector
        # that discards that is discarding the signal the platform publishes.
        assert hard_bounds(9.1, 6.5, 4.0).score == 0.0

    def test_names_the_actual_numbers(self) -> None:
        # A reviewer needs the figures, not just a verdict.
        message = hard_bounds(650.0, 6.5, 4.0).reasons[0].message

        assert "650" in message and "6.50" in message


class TestRobustOutlier:
    def test_stays_silent_on_too_few_comparisons(self) -> None:
        # Flagging on two observations would make the detector loudest exactly
        # where coverage is thinnest.
        assert robust_outlier(100.0, [10.0, 11.0], 3.5).score == 0.0

    def test_flags_a_clear_outlier(self) -> None:
        peers = [10.0, 10.2, 9.8, 10.1, 9.9, 10.3]

        assert robust_outlier(25.0, peers, 3.5).score > 0.5

    def test_accepts_ordinary_variation(self) -> None:
        peers = [10.0, 10.2, 9.8, 10.1, 9.9, 10.3]

        assert robust_outlier(10.4, peers, 3.5).score == 0.0

    def test_is_not_fooled_by_an_outlier_in_its_own_reference(self) -> None:
        # A mean and standard deviation would be dragged by the 500, hiding it.
        peers = [10.0, 10.2, 9.8, 10.1, 500.0, 10.3]

        assert robust_outlier(500.0, peers, 3.5).score > 0.0

    def test_handles_a_perfectly_flat_distribution(self) -> None:
        # MAD is zero here; dividing by it would raise.
        result = robust_outlier(20.0, [10.0] * 8, 3.5)

        assert result.score > 0.0
        assert result.reasons[0].code == "flat_distribution_deviation"

    def test_accepts_an_identical_price_in_a_flat_distribution(self) -> None:
        assert robust_outlier(10.0, [10.0] * 8, 3.5).score == 0.0


class TestRoundness:
    @pytest.mark.parametrize(("price", "expected"), [(100.0, 1.0), (50.0, 0.9), (9.73, 0.0)])
    def test_scores_round_numbers_higher(self, price: float, expected: float) -> None:
        assert roundness(price) == expected

    def test_handles_a_non_positive_price(self) -> None:
        assert roundness(0.0) == 0.0


class TestDetector:
    def test_combines_layers_with_a_maximum_not_an_average(self) -> None:
        # Averaging would let two quiet layers dilute one that is certain.
        detector = AnomalyDetector()
        verdict = detector.score(
            Observation(
                submission_id="s1",
                price=6500.0,
                item_reference_median=6.5,
                local_prices=[6.4, 6.5, 6.6, 6.5, 6.4],
            )
        )

        assert verdict.verdict == VERDICT_REJECTED
        assert verdict.layer_scores["bounds"] == 1.0

    def test_an_ordinary_submission_is_clean(self) -> None:
        detector = AnomalyDetector()
        verdict = detector.score(
            Observation(
                submission_id="s2",
                price=6.5,
                item_reference_median=6.5,
                local_prices=[6.4, 6.5, 6.6, 6.5, 6.4],
            )
        )

        assert verdict.verdict == VERDICT_CLEAN
        assert verdict.reasons == []

    def test_every_flag_carries_a_readable_reason(self) -> None:
        detector = AnomalyDetector()
        verdict = detector.score(
            Observation(submission_id="s3", price=6500.0, item_reference_median=6.5)
        )

        assert verdict.reasons
        assert all(len(r["message"]) > 20 for r in verdict.reasons)

    def test_refuses_to_train_the_forest_on_too_little_data(self) -> None:
        detector = AnomalyDetector()
        features = [build_features(Observation(submission_id=str(i), price=10.0)) for i in range(5)]

        assert not detector.fit(features)
        assert not detector.forest_is_trained

    def test_trains_on_enough_data(self) -> None:
        detector = AnomalyDetector()
        features = [
            build_features(Observation(submission_id=str(i), price=10.0 + i % 5))
            for i in range(120)
        ]

        assert detector.fit(features)
        assert detector.forest_is_trained

    def test_scores_without_a_trained_forest(self) -> None:
        # Cold start: the other two layers must still work.
        verdict = AnomalyDetector().score(
            Observation(submission_id="s4", price=6500.0, item_reference_median=6.5)
        )

        assert verdict.layer_scores["isolation_forest"] == 0.0
        assert verdict.score == 1.0

    def test_thresholds_are_configurable(self) -> None:
        detector = AnomalyDetector(DetectorConfig(reject_threshold=0.1, suspect_threshold=0.05))
        verdict = detector.score(
            Observation(submission_id="s5", price=6500.0, item_reference_median=6.5)
        )

        assert verdict.verdict == VERDICT_REJECTED


class TestReporterBias:
    """Cover for the measured failure that produced this layer.

    Observation-level tests caught 5.3% of coordinated manipulation. The first
    reporter-level attempt used the median and caught 0%, because a manipulator
    falsifies only part of what they submit and the median is dominated by the
    honest majority — robust against the very signal being hunted. The lower
    decile catches all of it.
    """

    @staticmethod
    def _records(reporter: str, ratios: list[float]) -> list[dict]:
        return [{"reporter_id": reporter, "price": 10.0 * r, "reference": 10.0} for r in ratios]

    def test_finds_a_partially_manipulating_reporter(self) -> None:
        records: list[dict] = []

        for honest in ("h1", "h2", "h3", "h4"):
            records += self._records(honest, [1.0, 0.98, 1.02, 1.01, 0.99] * 4)

        # Only a fifth of this reporter's submissions are falsified — which is
        # what made the median useless.
        records += self._records("bad", ([0.7, 1.0, 0.99, 1.01, 1.02]) * 4)

        flagged = suspicious_reporter_ids(detect_bias(records))

        assert "bad" in flagged

    def test_does_not_flag_consistent_honest_reporters(self) -> None:
        records: list[dict] = []
        for reporter in ("a", "b", "c", "d"):
            records += self._records(reporter, [1.0, 0.98, 1.02, 1.01, 0.99] * 4)

        assert suspicious_reporter_ids(detect_bias(records)) == set()

    def test_ignores_reporters_with_too_little_history(self) -> None:
        # An honest newcomer who visited two cheap shops must not be branded.
        records: list[dict] = []
        for reporter in ("a", "b", "c"):
            records += self._records(reporter, [1.0] * 20)
        records += self._records("new", [0.5, 0.5])

        assert "new" not in suspicious_reporter_ids(detect_bias(records))

    def test_returns_nothing_without_a_population_to_compare_against(self) -> None:
        assert detect_bias(self._records("only", [1.0] * 20)) == []

    def test_skips_records_with_no_usable_reference(self) -> None:
        records = [{"reporter_id": "a", "price": 10.0, "reference": None}] * 20

        assert detect_bias(records) == []

    def test_explains_itself_in_words(self) -> None:
        records: list[dict] = []
        for reporter in ("a", "b", "c", "d"):
            records += self._records(reporter, [1.0, 0.99, 1.01] * 7)
        records += self._records("bad", ([0.7, 1.0, 0.99, 1.01]) * 6)

        result = next(r for r in detect_bias(records) if r.reporter_id == "bad")

        assert result.is_suspicious
        assert "decile" in result.reason.lower()
        assert "observations" in result.reason
