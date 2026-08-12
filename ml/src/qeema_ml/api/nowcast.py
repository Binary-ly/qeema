# SPDX-License-Identifier: Apache-2.0
"""Nowcasting endpoints."""

from __future__ import annotations

from fastapi import APIRouter
from pydantic import BaseModel, Field

from qeema_ml import __version__
from qeema_ml.nowcast.model import MIN_TRAINING_ROWS, NowcastFeatures, NowcastModel, impute

router = APIRouter(prefix="/v1/nowcast", tags=["nowcast"])

#: One fitted model per country, rather than one for everybody.
#:
#: This was a single module-level model, which meant training Libya and then
#: Venezuela left only Venezuela's fit answering for both. Targets are ratios to
#: a national median, so the model is scale-free and the result was not
#: nonsense — it was simply whichever country had been trained most recently,
#: which is not a decision anybody made.
#:
#: Countries are a small, operator-defined set, so a plain dictionary is the
#: right size of solution. It is keyed on the code Laravel sends and nothing
#: else creates entries.
_models: dict[str, NowcastModel] = {}


def model_for(country: str) -> NowcastModel:
    """The model serving a country, created unfitted on first use."""
    return _models.setdefault(country.upper(), NowcastModel())


def reset_models() -> None:
    """Forget every fitted model. For tests, and for an operator starting over."""
    _models.clear()


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
    #: Which country's model to use. Required: serving one country's prices
    #: from another country's model is the bug this field exists to prevent,
    #: and a default would let it happen silently.
    country: str = Field(min_length=2, max_length=8)
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
    country: str = Field(min_length=2, max_length=8)
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
    model = model_for(request.country)
    results = []

    for item in request.requests:
        prediction = impute(model if model.is_trained else None, item.to_features())

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
        model_trained=model.is_trained,
    )


@router.post("/train", response_model=TrainResponse)
def train(request: TrainRequest) -> TrainResponse:
    model = model_for(request.country)

    if len(request.features) != len(request.targets):
        return TrainResponse(
            trained=False,
            n_samples=model.trained_rows,
            reason="features and targets must be the same length.",
        )

    trained = model.fit([f.to_features() for f in request.features], request.targets)

    return TrainResponse(
        trained=trained,
        n_samples=model.trained_rows,
        reason=(
            f"Trained on {model.trained_rows} rows for {request.country.upper()}."
            if trained
            else f"Need at least {MIN_TRAINING_ROWS} rows, got {len(request.features)}."
        ),
    )
