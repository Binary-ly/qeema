# SPDX-License-Identifier: Apache-2.0
"""Price nowcasting with LightGBM quantile regression.

Coverage is thin by nature — 7% of (location, item) pairs are never observed at
all, and whole location-weeks go blank. Without imputation, `cost_local` prices
only the *observed* part of the basket, which makes a sparsely-covered location
look **cheaper** than a well-covered one. That is exactly backwards: thin
coverage usually accompanies harder conditions.

So imputation is not a nicety here. It is what makes two locations comparable.

Three commitments shape the design.

**Quantile regression, not point prediction.** Three separate models at τ = 0.1,
0.5 and 0.9. A point estimate with no interval invites a reader to treat a guess
as a measurement; the spread between the outer quantiles is the honest statement
of how much is actually known.

**Every imputed value is flagged, always.** The model returns its method and its
interval, and those travel into the snapshot, the API and the UI. An imputed
value silently mixed with observed ones would do more damage to this platform's
credibility than any amount of imputation error.

**It refuses rather than guesses.** With too little history to train on, the
model declines and the caller falls back to a documented, clearly-labelled
heuristic. A confidently-wrong price is worse than an obviously-approximate one.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

try:  # pragma: no cover - exercised implicitly by the training tests
    import lightgbm as lgb
except ImportError:  # pragma: no cover
    lgb = None  # type: ignore[assignment]

#: Quantiles trained. The outer pair forms an 80% interval.
QUANTILES: tuple[float, ...] = (0.1, 0.5, 0.9)

#: Below this many training rows a fitted model would be memorising noise.
MIN_TRAINING_ROWS = 200

METHOD_MODEL = "lightgbm_quantile"
METHOD_FALLBACK_LOCAL = "fallback_local_median"
METHOD_FALLBACK_NATIONAL = "fallback_national_median"


@dataclass(frozen=True, slots=True)
class NowcastFeatures:
    """Context for imputing one (location, item, date).

    Every feature is a ratio or a difference rather than an absolute price.
    Absolute prices are not comparable across items or across months of
    inflation, and a model trained on them would learn "expensive item" as a
    prediction rather than a property.
    """

    #: Median price for this item nationally, in the recent window.
    national_median: float
    #: Median among the k nearest locations that did report.
    neighbour_median: float
    #: Distance-weighted mean of those neighbours.
    neighbour_weighted: float
    #: How many neighbours contributed.
    neighbour_count: float
    #: Distance in km to the nearest reporting neighbour.
    nearest_neighbour_km: float
    #: This location's own last observed price for the item, if any.
    last_local_price: float
    #: Days since that observation.
    days_since_local: float
    #: National trend: this week's median over the previous week's.
    national_trend: float
    #: Exchange rate now, relative to 30 days ago.
    fx_change_30d: float
    #: Median ratio of this location's prices to national, across all items.
    location_price_level: float
    #: Day of the week, which matters for market days.
    day_of_week: float

    def as_vector(self) -> list[float]:
        return [
            self.national_median,
            self.neighbour_median,
            self.neighbour_weighted,
            self.neighbour_count,
            self.nearest_neighbour_km,
            self.last_local_price,
            self.days_since_local,
            self.national_trend,
            self.fx_change_30d,
            self.location_price_level,
            self.day_of_week,
        ]

    @staticmethod
    def names() -> list[str]:
        return [
            "national_median",
            "neighbour_median",
            "neighbour_weighted",
            "neighbour_count",
            "nearest_neighbour_km",
            "last_local_price",
            "days_since_local",
            "national_trend",
            "fx_change_30d",
            "location_price_level",
            "day_of_week",
        ]


@dataclass(frozen=True, slots=True)
class Imputation:
    """An imputed price, with its uncertainty and its provenance."""

    value: float
    lower: float
    upper: float
    method: str

    @property
    def is_fallback(self) -> bool:
        return self.method != METHOD_MODEL

    @property
    def relative_width(self) -> float:
        """Interval width as a fraction of the estimate."""
        return (self.upper - self.lower) / self.value if self.value > 0 else 0.0


@dataclass
class NowcastModel:
    """Quantile models for price imputation."""

    _models: dict[float, object] = field(default_factory=dict)
    _trained_rows: int = 0

    @property
    def is_trained(self) -> bool:
        return len(self._models) == len(QUANTILES)

    @property
    def trained_rows(self) -> int:
        return self._trained_rows

    def fit(self, features: list[NowcastFeatures], targets: list[float]) -> bool:
        """Train one model per quantile. Returns whether training happened."""
        if lgb is None:  # pragma: no cover - dependency is declared
            return False

        if len(features) != len(targets):
            raise ValueError("features and targets must be the same length.")

        if len(features) < MIN_TRAINING_ROWS:
            return False

        x = np.array([f.as_vector() for f in features], dtype=float)
        y = np.array(targets, dtype=float)

        # Targets are ratios to the national median, not absolute prices, so one
        # model serves every item regardless of price scale. Predicting absolute
        # prices would need a model per item and would relearn inflation as
        # signal every month.
        models: dict[float, object] = {}

        for quantile in QUANTILES:
            model = lgb.LGBMRegressor(
                objective="quantile",
                alpha=quantile,
                n_estimators=200,
                learning_rate=0.05,
                num_leaves=15,
                min_child_samples=20,
                random_state=20260101,
                n_jobs=1,
                verbose=-1,
            )
            model.fit(x, y)
            models[quantile] = model

        self._models = models
        self._trained_rows = len(features)

        return True

    def predict(self, features: NowcastFeatures) -> Imputation | None:
        """Impute a price, or None if the model cannot.

        Returns a *ratio-scaled* prediction multiplied back by the national
        median, so the caller receives a price in local currency.
        """
        if not self.is_trained:
            return None

        anchor = features.national_median

        if anchor <= 0:
            # Nothing to scale a ratio against.
            return None

        vector = np.array([features.as_vector()], dtype=float)
        predictions = {
            q: float(model.predict(vector)[0])  # type: ignore[attr-defined]
            for q, model in self._models.items()
        }

        lower = predictions[0.1] * anchor
        median = predictions[0.5] * anchor
        upper = predictions[0.9] * anchor

        # Quantile models are fitted independently and can cross on sparse
        # data, producing a "lower" bound above the "upper" one. Sorting is the
        # standard remedy and is preferable to publishing an inverted interval
        # that would read as nonsense.
        lower, median, upper = sorted((lower, median, upper))

        return Imputation(
            value=round(median, 6),
            lower=round(lower, 6),
            upper=round(upper, 6),
            method=METHOD_MODEL,
        )


def fallback(features: NowcastFeatures) -> Imputation | None:
    """Impute without a model, from whatever context exists.

    Used before the model is trained and whenever it declines. The interval is
    deliberately wide — a heuristic should look as uncertain as it is, and the
    method name says plainly which one was used.
    """
    if features.neighbour_median > 0:
        value = features.neighbour_median
        method = METHOD_FALLBACK_LOCAL
        spread = 0.30
    elif features.national_median > 0:
        value = features.national_median
        method = METHOD_FALLBACK_NATIONAL
        # Wider still: a national median says little about one town.
        spread = 0.45
    else:
        return None

    # Scaled by this location's own price level where known, so a structurally
    # dearer town is not imputed at the national average.
    level = features.location_price_level if features.location_price_level > 0 else 1.0
    value *= level

    return Imputation(
        value=round(value, 6),
        lower=round(value * (1 - spread), 6),
        upper=round(value * (1 + spread), 6),
        method=method,
    )


def impute(model: NowcastModel | None, features: NowcastFeatures) -> Imputation | None:
    """Best available imputation: the model if it can, else the heuristic."""
    if model is not None:
        prediction = model.predict(features)

        if prediction is not None:
            return prediction

    return fallback(features)
