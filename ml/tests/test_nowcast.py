# SPDX-License-Identifier: Apache-2.0
"""Price nowcasting.

The tests that matter are about honesty rather than accuracy: that an imputed
value is always labelled, that an interval is well-formed, and that the model
declines rather than guessing when it cannot know.
"""

from __future__ import annotations

import pytest

from qeema_ml.nowcast.model import (
    METHOD_FALLBACK_LOCAL,
    METHOD_FALLBACK_NATIONAL,
    METHOD_MODEL,
    MIN_TRAINING_ROWS,
    NowcastFeatures,
    NowcastModel,
    fallback,
    impute,
)


def features(**overrides) -> NowcastFeatures:
    base = dict(
        national_median=10.0,
        neighbour_median=10.5,
        neighbour_weighted=10.4,
        neighbour_count=3.0,
        nearest_neighbour_km=40.0,
        last_local_price=10.2,
        days_since_local=3.0,
        national_trend=1.01,
        fx_change_30d=1.02,
        location_price_level=1.05,
        day_of_week=2.0,
    )
    base.update(overrides)
    return NowcastFeatures(**base)


def training_set(n: int = MIN_TRAINING_ROWS + 50):
    rows, targets = [], []
    for i in range(n):
        level = 0.9 + (i % 20) / 100
        rows.append(features(location_price_level=level, neighbour_median=10.0 * level))
        targets.append(level)
    return rows, targets


class TestFallback:
    def test_prefers_neighbours_over_the_national_median(self) -> None:
        # A neighbouring town says more about a price than a national figure.
        result = fallback(features(neighbour_median=12.0, national_median=10.0))

        assert result.method == METHOD_FALLBACK_LOCAL

    def test_falls_back_to_national_when_no_neighbour_reported(self) -> None:
        result = fallback(features(neighbour_median=0.0, national_median=10.0))

        assert result.method == METHOD_FALLBACK_NATIONAL

    def test_a_weaker_source_gets_a_wider_interval(self) -> None:
        # A heuristic should look as uncertain as it is.
        local = fallback(features(neighbour_median=10.0, national_median=10.0))
        national = fallback(features(neighbour_median=0.0, national_median=10.0))

        assert national.relative_width > local.relative_width

    def test_scales_by_the_location_price_level(self) -> None:
        # A structurally dearer town must not be imputed at the national average.
        dear = fallback(
            features(neighbour_median=0.0, national_median=10.0, location_price_level=1.3)
        )

        assert dear.value == pytest.approx(13.0)

    def test_returns_nothing_when_there_is_no_context_at_all(self) -> None:
        assert fallback(features(neighbour_median=0.0, national_median=0.0)) is None

    def test_is_always_labelled_a_fallback(self) -> None:
        assert fallback(features()).is_fallback


class TestModel:
    def test_refuses_to_train_on_too_little_data(self) -> None:
        model = NowcastModel()
        rows, targets = training_set(n=20)

        assert not model.fit(rows, targets)
        assert not model.is_trained

    def test_trains_on_enough_data(self) -> None:
        model = NowcastModel()
        rows, targets = training_set()

        assert model.fit(rows, targets)
        assert model.is_trained
        assert model.trained_rows >= MIN_TRAINING_ROWS

    def test_rejects_mismatched_inputs(self) -> None:
        with pytest.raises(ValueError, match="same length"):
            NowcastModel().fit([features()], [1.0, 2.0])

    def test_predicts_nothing_before_training(self) -> None:
        assert NowcastModel().predict(features()) is None

    def test_predicts_nothing_without_an_anchor(self) -> None:
        # A ratio prediction needs something to scale against.
        model = NowcastModel()
        model.fit(*training_set())

        assert model.predict(features(national_median=0.0)) is None

    def test_produces_an_ordered_interval(self) -> None:
        # Independently-fitted quantile models can cross on sparse data; an
        # inverted interval would read as nonsense.
        model = NowcastModel()
        model.fit(*training_set())

        prediction = model.predict(features())

        assert prediction.lower <= prediction.value <= prediction.upper

    def test_labels_a_model_prediction_as_such(self) -> None:
        model = NowcastModel()
        model.fit(*training_set())

        prediction = model.predict(features())

        assert prediction.method == METHOD_MODEL
        assert not prediction.is_fallback


class TestImpute:
    def test_uses_the_fallback_when_no_model_exists(self) -> None:
        result = impute(None, features())

        assert result.is_fallback

    def test_prefers_the_model_once_trained(self) -> None:
        model = NowcastModel()
        model.fit(*training_set())

        assert impute(model, features()).method == METHOD_MODEL

    def test_falls_back_when_the_model_declines(self) -> None:
        model = NowcastModel()
        model.fit(*training_set())

        # No anchor, so the model returns None and the heuristic takes over.
        result = impute(model, features(national_median=0.0, neighbour_median=9.0))

        assert result is not None
        assert result.is_fallback

    def test_returns_nothing_when_nothing_can_be_said(self) -> None:
        assert impute(None, features(neighbour_median=0.0, national_median=0.0)) is None
