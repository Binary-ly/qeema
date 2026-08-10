# SPDX-License-Identifier: Apache-2.0
"""Anomaly scoring endpoints.

Context travels with the request, like the matching catalogue does: Laravel owns
the observations, this service owns the opinion about which are suspicious.
"""

from __future__ import annotations

from dataclasses import asdict

from fastapi import APIRouter
from pydantic import BaseModel, Field

from qeema_ml import __version__
from qeema_ml.anomaly.detector import AnomalyDetector, DetectorConfig, Observation
from qeema_ml.config import get_settings

router = APIRouter(prefix="/v1/anomaly", tags=["anomaly"])

#: Process-wide detector, refitted as submissions accumulate.
_detector: AnomalyDetector | None = None


def _get_detector() -> AnomalyDetector:
    global _detector

    if _detector is None:
        settings = get_settings()
        _detector = AnomalyDetector(
            DetectorConfig(
                hard_bound_factor=settings.anomaly_hard_bound_factor,
                mad_threshold=settings.anomaly_mad_threshold,
                contamination=settings.anomaly_isolation_contamination,
            )
        )

    return _detector


class ObservationIn(BaseModel):
    submission_id: str
    price: float = Field(gt=0)
    local_prices: list[float] = Field(default_factory=list)
    national_median: float | None = None
    item_reference_median: float | None = None
    trend_expected: float | None = None
    reporter_mean_deviation: float = 0.0
    reporter_submission_rate: float = 1.0
    hour_of_day: int = Field(default=12, ge=0, le=23)
    days_since_last_local_report: float = 1.0


class ScoreRequest(BaseModel):
    observations: list[ObservationIn] = Field(min_length=1, max_length=1000)


class VerdictOut(BaseModel):
    submission_id: str
    score: float
    verdict: str
    reasons: list[dict[str, str]]
    layer_scores: dict[str, float]


class ScoreResponse(BaseModel):
    results: list[VerdictOut]
    model_version: str
    forest_trained: bool


class TrainRequest(BaseModel):
    observations: list[ObservationIn] = Field(min_length=1)


class TrainResponse(BaseModel):
    trained: bool
    n_samples: int
    reason: str


@router.post("/score", response_model=ScoreResponse)
def score(request: ScoreRequest) -> ScoreResponse:
    detector = _get_detector()

    verdicts = detector.score_many([Observation(**o.model_dump()) for o in request.observations])

    return ScoreResponse(
        # asdict(), not __dict__: AnomalyVerdict is a slotted dataclass and has
        # no instance dictionary at all, so __dict__ raised AttributeError and
        # every call to this endpoint returned a 500. Nothing noticed, because
        # every anomaly test exercised the detector directly and none of them
        # went through HTTP — the same shape of gap as a pipeline stage with no
        # caller.
        results=[VerdictOut(**asdict(v)) for v in verdicts],
        model_version=f"anomaly-{__version__}",
        forest_trained=detector.forest_is_trained,
    )


@router.post("/train", response_model=TrainResponse)
def train(request: TrainRequest) -> TrainResponse:
    """Fit the isolation forest on recent submissions.

    Unsupervised, so it trains on everything rather than on labels — the point
    is to learn what ordinary submissions look like, and a small share of bad
    ones among them is what the contamination parameter is for.
    """
    from qeema_ml.anomaly.detector import build_features

    detector = _get_detector()
    features = [build_features(Observation(**o.model_dump())) for o in request.observations]

    trained = detector.fit(features)

    return TrainResponse(
        trained=trained,
        n_samples=detector.trained_on,
        reason=(
            f"Trained on {detector.trained_on} submissions."
            if trained
            else f"Need at least {DetectorConfig().min_samples_for_forest} submissions, "
            f"got {len(features)}."
        ),
    )
