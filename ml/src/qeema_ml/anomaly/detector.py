# SPDX-License-Identifier: Apache-2.0
"""Combining the three anomaly layers into a verdict."""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
from sklearn.ensemble import IsolationForest

from qeema_ml.anomaly.layers import (
    LayerScore,
    ObservationFeatures,
    Reason,
    hard_bounds,
    robust_outlier,
)

VERDICT_CLEAN = "clean"
VERDICT_SUSPECT = "suspect"
VERDICT_REJECTED = "rejected"


@dataclass(frozen=True, slots=True)
class Observation:
    """One submission, with the context needed to judge it."""

    submission_id: str
    price: float
    local_prices: list[float] = field(default_factory=list)
    national_median: float | None = None
    item_reference_median: float | None = None
    trend_expected: float | None = None
    reporter_mean_deviation: float = 0.0
    reporter_submission_rate: float = 1.0
    hour_of_day: int = 12
    days_since_last_local_report: float = 1.0


@dataclass(frozen=True, slots=True)
class AnomalyVerdict:
    submission_id: str
    score: float
    verdict: str
    reasons: list[dict[str, str]]
    layer_scores: dict[str, float]


@dataclass
class DetectorConfig:
    hard_bound_factor: float = 4.0
    mad_threshold: float = 3.5
    contamination: float = 0.05
    #: At or above this the submission never reaches the index.
    reject_threshold: float = 0.85
    #: At or above this a human is asked to look.
    suspect_threshold: float = 0.45
    min_samples_for_forest: int = 50


class AnomalyDetector:
    """Scores submissions across three layers.

    The layers are combined with a **maximum**, not an average. Averaging would
    let two quiet layers dilute one that is certain: a price a thousand times
    the going rate is anomalous whatever the isolation forest thinks of the hour
    it was submitted at. Each layer is an independent reason for suspicion, and
    any one of them being sure is enough.

    The corollary is that a layer must stay quiet when it has nothing to say —
    hence the explicit silence in each when context is missing, rather than a
    default score that would accumulate into false positives.
    """

    def __init__(self, config: DetectorConfig | None = None) -> None:
        self._config = config or DetectorConfig()
        self._forest: IsolationForest | None = None
        self._trained_on = 0

    @property
    def forest_is_trained(self) -> bool:
        return self._forest is not None

    @property
    def trained_on(self) -> int:
        return self._trained_on

    def fit(self, features: list[ObservationFeatures]) -> bool:
        """Train the isolation forest on observed submissions.

        Refuses on too little data rather than fitting a model that would
        confidently partition noise. Returns whether training happened.
        """
        if len(features) < self._config.min_samples_for_forest:
            return False

        matrix = np.array([f.as_vector() for f in features], dtype=float)

        forest = IsolationForest(
            n_estimators=200,
            contamination=self._config.contamination,
            random_state=20260101,
            n_jobs=1,
        )
        forest.fit(matrix)

        self._forest = forest
        self._trained_on = len(features)

        return True

    def score(self, observation: Observation) -> AnomalyVerdict:
        bounds = hard_bounds(
            observation.price,
            observation.item_reference_median,
            self._config.hard_bound_factor,
        )

        robust = robust_outlier(
            observation.price,
            observation.local_prices,
            self._config.mad_threshold,
        )

        forest = self._score_forest(observation)

        score = max(bounds.score, robust.score, forest.score)
        reasons = [*bounds.reasons, *robust.reasons, *forest.reasons]

        return AnomalyVerdict(
            submission_id=observation.submission_id,
            score=round(score, 4),
            verdict=self._verdict(score),
            reasons=[r.as_dict() for r in reasons],
            layer_scores={
                "bounds": round(bounds.score, 4),
                "robust": round(robust.score, 4),
                "isolation_forest": round(forest.score, 4),
            },
        )

    def score_many(self, observations: list[Observation]) -> list[AnomalyVerdict]:
        return [self.score(o) for o in observations]

    def _score_forest(self, observation: Observation) -> LayerScore:
        if self._forest is None:
            return LayerScore(0.0)

        features = build_features(observation)
        raw = float(self._forest.decision_function([features.as_vector()])[0])

        # decision_function is positive for inliers and negative for outliers.
        # Mapped so an inlier scores 0 and a clear outlier approaches 1, rather
        # than exposing a raw value whose scale means nothing to a reviewer.
        if raw >= 0:
            return LayerScore(0.0)

        score = float(min(1.0, abs(raw) * 4.0))

        if score < 0.3:
            # Weak evidence on its own; reported without a reason so it can
            # still contribute if another layer agrees, without cluttering a
            # reviewer's screen with near-noise.
            return LayerScore(score)

        return LayerScore(
            score,
            [
                Reason(
                    "isolation_forest",
                    "This submission's combination of price, timing and reporter "
                    "history is unlike normal submissions, even though no single "
                    "value is out of range.",
                    "isolation_forest",
                )
            ],
        )

    def _verdict(self, score: float) -> str:
        if score >= self._config.reject_threshold:
            return VERDICT_REJECTED

        if score >= self._config.suspect_threshold:
            return VERDICT_SUSPECT

        return VERDICT_CLEAN


def build_features(observation: Observation) -> ObservationFeatures:
    """Turn an observation and its context into model features."""
    from qeema_ml.anomaly.layers import roundness

    local_median = float(np.median(observation.local_prices)) if observation.local_prices else 0.0

    return ObservationFeatures(
        price_to_local_median=_ratio(observation.price, local_median),
        price_to_national_median=_ratio(observation.price, observation.national_median),
        deviation_from_trend=_ratio(observation.price, observation.trend_expected) - 1.0,
        reporter_mean_deviation=observation.reporter_mean_deviation,
        reporter_submission_rate=observation.reporter_submission_rate,
        roundness=roundness(observation.price),
        hour_of_day=float(observation.hour_of_day),
        days_since_last_local_report=float(observation.days_since_last_local_report),
    )


def _ratio(price: float, reference: float | None) -> float:
    """Price relative to a reference, defaulting to 1.0 when unknown.

    1.0 means "indistinguishable from the reference", which is the right
    neutral value: an unknown reference must not read as an extreme ratio and
    push an ordinary submission towards suspicion.
    """
    if reference is None or reference <= 0:
        return 1.0

    return price / reference
