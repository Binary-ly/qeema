# SPDX-License-Identifier: Apache-2.0
"""Nowcasting endpoints."""

from __future__ import annotations

from fastapi import APIRouter
from pydantic import BaseModel, Field

from qeema_ml import __version__
from qeema_ml.nowcast.model import MIN_TRAINING_ROWS, NowcastFeatures, NowcastModel, impute

router = APIRouter(prefix="/v1/nowcast", tags=["nowcast"])

_model = NowcastModel()


class FeaturesIn(BaseModel):
    national_median: float = 0.0
    neighbour_median: float = 0.0
    neighbour_weighted: float = 0.0
    neighbour_count: float = 0.0
    nearest_neighbour_km: float = 0.0
    last_local_price: float = 0.0
    days_since_local: float = 30.0
    national_trend: float = 1.0
    fx_change_30d: float = 1.0
    location_price_level: float = 1.0
    day_of_week: float = 0.0

    def to_features(self) -> NowcastFeatures:
        return NowcastFeatures(**self.model_dump())


class ImputeRequest(BaseModel):
    requests: list[FeaturesIn] = Field(min_length=1, max_length=2000)


class ImputationOut(BaseModel):
    value: float | None
    lower: float | None
    upper: float | None
    method: str | None
    is_imputed: bool = True


class ImputeResponse(BaseModel):
    results: list[ImputationOut]
    model_version: str
    model_trained: bool


class TrainRequest(BaseModel):
    features: list[FeaturesIn] = Field(min_length=1)
    targets: list[float] = Field(min_length=1)


class TrainResponse(BaseModel):
    trained: bool
    n_samples: int
    reason: str


@router.post("/impute", response_model=ImputeResponse)
def impute_prices(request: ImputeRequest) -> ImputeResponse:
    """Impute prices, always labelled as imputed.

    `is_imputed` is unconditionally true on every result. There is no code path
    through this endpoint that produces an unlabelled value, because a caller
    that forgot to check a flag would silently publish an estimate as a
    measurement.
    """
    results = []

    for item in request.requests:
        prediction = impute(_model if _model.is_trained else None, item.to_features())

        results.append(
            ImputationOut(
                value=None if prediction is None else prediction.value,
                lower=None if prediction is None else prediction.lower,
                upper=None if prediction is None else prediction.upper,
                method=None if prediction is None else prediction.method,
            )
        )

    return ImputeResponse(
        results=results,
        model_version=f"nowcast-{__version__}",
        model_trained=_model.is_trained,
    )


@router.post("/train", response_model=TrainResponse)
def train(request: TrainRequest) -> TrainResponse:
    if len(request.features) != len(request.targets):
        return TrainResponse(
            trained=False,
            n_samples=_model.trained_rows,
            reason="features and targets must be the same length.",
        )

    trained = _model.fit([f.to_features() for f in request.features], request.targets)

    return TrainResponse(
        trained=trained,
        n_samples=_model.trained_rows,
        reason=(
            f"Trained on {_model.trained_rows} rows."
            if trained
            else f"Need at least {MIN_TRAINING_ROWS} rows, got {len(request.features)}."
        ),
    )
