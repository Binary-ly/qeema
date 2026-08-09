# SPDX-License-Identifier: Apache-2.0
"""The three anomaly-detection layers.

Each catches something the others cannot, and each fails differently:

**Hard bounds** catch the impossible — a decimal slip or a unit confusion that
puts a price three orders of magnitude out. Cheap, unambiguous, and the only
layer that can act on a single observation with no context.

**Robust statistics** catch the implausible — a price that is out of line with
what this item costs in this place right now. Uses the median and MAD rather
than mean and standard deviation, because a mean is dragged by the very
outliers it is supposed to find, and one bad value inflates the standard
deviation enough to hide itself.

**Isolation Forest** catches the *pattern* that neither of the above can see: a
price that is individually plausible but arrives with a combination of features
that legitimate submissions do not have. This is the layer that has a chance
against coordinated manipulation, where every single figure looks fine.

Crucially, none of them may fire on a genuine supply shock. Prices in these
economies really do jump 40% in a week, and a detector that discards that is
discarding exactly the signal the platform exists to publish.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

#: Converts MAD to a standard-deviation-equivalent scale for a normal
#: distribution, so the resulting z-score reads on the familiar scale.
MAD_TO_SIGMA = 0.6745


@dataclass(frozen=True, slots=True)
class Reason:
    """A human-readable explanation attached to a score.

    Reviewers get sentences, not numbers. A reviewer who cannot tell why
    something was flagged will either rubber-stamp it or ignore it, and both
    defeat the queue.
    """

    code: str
    message: str
    layer: str

    def as_dict(self) -> dict[str, str]:
        return {"code": self.code, "message": self.message, "layer": self.layer}


@dataclass
class LayerScore:
    score: float
    reasons: list[Reason] = field(default_factory=list)


def hard_bounds(
    price: float,
    reference_median: float | None,
    factor: float,
) -> LayerScore:
    """Layer 1 — is this price even possible for this item?

    Bounds are derived from the item's own trailing distribution rather than
    hardcoded, which is what keeps them country-agnostic and self-tuning: a
    threshold written for one currency and one inflation regime is wrong
    everywhere else within months.
    """
    if reference_median is None or reference_median <= 0:
        # No history yet. Silence rather than a guess — a cold-start item must
        # not have everything rejected before any reference exists.
        return LayerScore(0.0)

    upper = reference_median * factor
    lower = reference_median / factor

    if price > upper:
        ratio = price / reference_median

        return LayerScore(
            1.0,
            [
                Reason(
                    "hard_bounds_high",
                    f"Price is {ratio:.1f}x the recent typical price for this item "
                    f"({price:,.2f} against {reference_median:,.2f}).",
                    "bounds",
                )
            ],
        )

    if price < lower:
        ratio = reference_median / max(price, 1e-9)

        return LayerScore(
            1.0,
            [
                Reason(
                    "hard_bounds_low",
                    f"Price is {ratio:.1f}x below the recent typical price for this item "
                    f"({price:,.2f} against {reference_median:,.2f}).",
                    "bounds",
                )
            ],
        )

    return LayerScore(0.0)


def robust_outlier(
    price: float,
    comparison_prices: list[float],
    threshold: float,
    min_samples: int = 4,
) -> LayerScore:
    """Layer 2 — is this price out of line with its local peers?

    Returns silence rather than a guess when there is too little to compare
    against. Flagging on two observations would make the detector loudest
    exactly where coverage is thinnest, which is where the platform can least
    afford false positives.
    """
    if len(comparison_prices) < min_samples:
        return LayerScore(0.0)

    values = np.asarray(comparison_prices, dtype=float)
    median = float(np.median(values))
    mad = float(np.median(np.abs(values - median)))

    if mad <= 0:
        # Every comparison price is identical. A different value is suspicious
        # but MAD gives no scale, so fall back to a relative check rather than
        # dividing by zero.
        if median > 0 and abs(price - median) / median > 0.5:
            return LayerScore(
                0.6,
                [
                    Reason(
                        "flat_distribution_deviation",
                        f"Every recent report for this item is {median:,.2f}; "
                        f"this one is {price:,.2f}.",
                        "robust",
                    )
                ],
            )

        return LayerScore(0.0)

    modified_z = MAD_TO_SIGMA * (price - median) / mad

    if abs(modified_z) < threshold:
        return LayerScore(0.0)

    # Scaled so that a z at the threshold scores 0.5 and grows towards 1,
    # rather than jumping straight to certainty the moment it is crossed.
    score = float(min(1.0, 0.5 + 0.1 * (abs(modified_z) - threshold)))
    direction = "above" if modified_z > 0 else "below"

    return LayerScore(
        score,
        [
            Reason(
                "robust_outlier",
                f"Price is {abs(modified_z):.1f} robust deviations {direction} the local "
                f"median of {median:,.2f} for this item.",
                "robust",
            )
        ],
    )


@dataclass(frozen=True, slots=True)
class ObservationFeatures:
    """Engineered features for the isolation forest.

    Ratios rather than absolute prices throughout: an absolute price is not
    comparable across items or across months of inflation, and a model trained
    on absolutes would relearn "expensive item" as "anomaly".
    """

    price_to_local_median: float
    price_to_national_median: float
    deviation_from_trend: float
    reporter_mean_deviation: float
    reporter_submission_rate: float
    roundness: float
    hour_of_day: float
    days_since_last_local_report: float

    def as_vector(self) -> list[float]:
        return [
            self.price_to_local_median,
            self.price_to_national_median,
            self.deviation_from_trend,
            self.reporter_mean_deviation,
            self.reporter_submission_rate,
            self.roundness,
            self.hour_of_day,
            self.days_since_last_local_report,
        ]

    @staticmethod
    def feature_names() -> list[str]:
        return [
            "price_to_local_median",
            "price_to_national_median",
            "deviation_from_trend",
            "reporter_mean_deviation",
            "reporter_submission_rate",
            "roundness",
            "hour_of_day",
            "days_since_last_local_report",
        ]


def roundness(price: float) -> float:
    """How suspiciously round a price is, in [0, 1].

    A fabricated price is far more likely to be exactly 10 or 100 than 9.73.
    On its own this means almost nothing — plenty of real prices are round —
    which is precisely why it belongs in a multivariate model rather than as a
    rule of its own.
    """
    if price <= 0:
        return 0.0

    for divisor, value in ((100.0, 1.0), (50.0, 0.9), (10.0, 0.7), (5.0, 0.5), (1.0, 0.3)):
        if abs(price / divisor - round(price / divisor)) < 1e-9:
            return value

    return 0.0
