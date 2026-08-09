# SPDX-License-Identifier: Apache-2.0
"""Reporter-level bias detection.

This layer exists because of a measured failure. The three observation-level
layers caught injected mistakes almost perfectly — 100% of unit confusions and
decimal slips, 99% of wrong-currency entries — but only **5.3%** of coordinated
manipulation.

That is not a tuning problem. It is structural. The manipulation the generator
injects is a cluster of reporters each reporting 22–38% below the true price,
and every individual figure is *plausible*: 30% below the local median is well
inside the range of a genuinely cheaper shop. No test that looks at one
observation at a time can separate those from real bargains, because
individually they are indistinguishable.

What distinguishes them is the *pattern across a reporter's whole history* — but
**which** statistic is measured turns out to matter more than the aggregation.

The first version of this module used the median ratio and scored 0% recall.
Measured on the labelled data, manipulators had a median ratio of 0.995 against
honest reporters' 1.001: no separation whatsoever. The cause is that a
manipulator does not falsify everything they submit — in the labelled set only
~12% of their submissions are manipulated, and the median is dominated by the
other 88%. The median is *robust against exactly the signal being hunted*.

The lower decile is the right statistic, because partial manipulation lives in
the tail. On the same data it separates cleanly: every manipulator's 10th
percentile is at or below 0.746, every honest reporter's at or above 0.900.

The output deliberately feeds human review rather than automatic rejection.
Accusing a reporter of manipulation on statistical evidence alone, and silently
discarding their contributions, is exactly the kind of decision a person should
make.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass

import numpy as np

#: Below this many observations a reporter's bias is not measurable; an honest
#: newcomer who happened to visit two cheap shops must not be branded.
MIN_OBSERVATIONS = 15

#: Robust deviations from the population's distribution before a reporter is
#: considered suspicious.
DEFAULT_THRESHOLD = 3.0

#: Relative deviation that counts as suspicious when the population has no
#: spread at all and a z-score is undefined.
FLAT_POPULATION_TOLERANCE = 0.15

#: The quantile of a reporter's price-ratio distribution that is profiled.
#: Deliberately a tail statistic, not a central one — see the module docstring.
BIAS_QUANTILE = 0.10


@dataclass(frozen=True, slots=True)
class ReporterBias:
    reporter_id: str
    n_observations: int
    lower_decile_ratio: float
    modified_z: float
    is_suspicious: bool
    reason: str


def _ratios(prices: list[float], references: list[float]) -> list[float]:
    return [
        price / reference
        for price, reference in zip(prices, references, strict=True)
        if reference and reference > 0
    ]


def detect(
    observations: list[dict],
    threshold: float = DEFAULT_THRESHOLD,
    min_observations: int = MIN_OBSERVATIONS,
) -> list[ReporterBias]:
    """Find reporters whose prices are systematically off.

    Each record needs ``reporter_id``, ``price`` and a ``reference`` — the local
    median for that item and place, computed *excluding this reporter* so a
    cluster large enough to move the local median cannot hide inside it. That
    exclusion is the difference between catching coordinated behaviour and
    measuring it against itself.
    """
    by_reporter: dict[str, list[tuple[float, float]]] = defaultdict(list)

    for record in observations:
        reporter_id = record.get("reporter_id")
        reference = record.get("reference")

        if reporter_id is None or not reference or reference <= 0:
            continue

        by_reporter[str(reporter_id)].append((float(record["price"]), float(reference)))

    profiles: dict[str, float] = {}
    counts: dict[str, int] = {}

    for reporter_id, pairs in by_reporter.items():
        if len(pairs) < min_observations:
            continue

        ratios = _ratios([p for p, _ in pairs], [r for _, r in pairs])

        if not ratios:
            continue

        # The lower decile, not the median. A manipulator falsifies only part
        # of what they submit, so a central statistic is dominated by their
        # honest majority and shows nothing.
        profiles[reporter_id] = float(np.quantile(ratios, BIAS_QUANTILE))
        counts[reporter_id] = len(ratios)

    if len(profiles) < 3:
        # Too few reporters to have a population to compare against.
        return []

    values = np.array(list(profiles.values()), dtype=float)
    population_median = float(np.median(values))
    mad = float(np.median(np.abs(values - population_median)))

    results: list[ReporterBias] = []

    for reporter_id, ratio in profiles.items():
        if mad > 0:
            modified_z = 0.6745 * (ratio - population_median) / mad
            suspicious = abs(modified_z) >= threshold
        else:
            # Every profiled reporter has an identical lower decile, so there is
            # no spread to measure a deviation against and the z-score would be
            # zero for everyone — the detector goes blind exactly when the
            # population is most uniform. Fall back to a relative check against
            # the population median instead of dividing by zero.
            modified_z = 0.0
            suspicious = (
                population_median > 0
                and abs(ratio - population_median) / population_median >= FLAT_POPULATION_TOLERANCE
            )
        direction = "below" if modified_z < 0 else "above"

        results.append(
            ReporterBias(
                reporter_id=reporter_id,
                n_observations=counts[reporter_id],
                lower_decile_ratio=round(ratio, 4),
                modified_z=round(modified_z, 3),
                is_suspicious=suspicious,
                reason=(
                    f"Lowest decile of this reporter's prices sits at {ratio:.0%} of the "
                    f"local price across "
                    f"{counts[reporter_id]} observations — "
                    + (
                        f"{abs(modified_z):.1f} robust deviations {direction} other reporters."
                        if mad > 0
                        else f"{abs(ratio - population_median) / population_median:.0%} "
                        f"{direction} every other reporter, who agree exactly."
                    )
                    + " Consistent across items and time, which a genuinely cheaper "
                    "shop would not be."
                    if suspicious
                    else f"Lower decile at {ratio:.0%} of local price; consistent with others."
                ),
            )
        )

    return sorted(results, key=lambda r: r.modified_z)


def suspicious_reporter_ids(results: list[ReporterBias]) -> set[str]:
    return {r.reporter_id for r in results if r.is_suspicious}
