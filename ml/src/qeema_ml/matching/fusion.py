# SPDX-License-Identifier: Apache-2.0
"""Fusing lexical and semantic scores into a calibrated confidence.

Two problems are solved here, and conflating them is the usual mistake.

**Fusion** decides the ranking. **Calibration** turns a score into something
that can be compared against a threshold and read as "how likely is this
right".

The second matters more than it sounds. Raw e5 cosine similarities cluster
high and close together — measured on this project's own data, a correct match
scored 0.875 and an obviously wrong one 0.808, a gap of 0.067. Thresholding
that directly would mean picking a number like 0.84 and hoping. Calibration
maps scores onto observed correctness, so a threshold of 0.9 means something.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
from sklearn.isotonic import IsotonicRegression


@dataclass(frozen=True, slots=True)
class FusedCandidate:
    canonical_item_id: int
    canonical_item_code: str
    lexical_score: float
    semantic_score: float
    fused_score: float
    confidence: float
    matched_variant: str | None = None


def fuse(
    lexical: dict[int, float],
    semantic: dict[int, float],
    lexical_weight: float,
    semantic_weight: float,
    semantic_available: bool = True,
) -> dict[int, float]:
    """Weighted linear combination of the two signals.

    Linear rather than reciprocal rank fusion, deliberately. RRF discards the
    magnitude of the scores and keeps only the ordering, which is the wrong
    trade here: the *absolute* lexical score carries real information (1.0 means
    an exact match after normalisation, and should be trusted almost blindly)
    that its rank alone would throw away.

    **Weights are renormalised over the signals that actually exist.** This is
    not a detail. With ``semantic_weight=0.6`` and no semantic index — the
    normal state of a deployment before anything has been embedded — a *perfect*
    lexical match of 1.0 fused to 0.40 and was rejected outright. Measured on
    the real catalogue, that routed 63.9% of correct matches to rejection while
    top-1 accuracy read 98.4%: the matcher was finding the right answer and then
    throwing it away.

    The distinction is between *absent evidence* and *evidence of absence*. A
    signal that was never computed must not count against a candidate; a signal
    that was computed and returned nothing for this item legitimately does.
    """
    if not semantic_available or not semantic:
        # Nothing to fuse with. The available signal carries the full weight
        # rather than being scaled down by a weight it cannot earn.
        return dict(lexical)

    total = lexical_weight + semantic_weight
    if total <= 0:
        lexical_weight, semantic_weight, total = 0.5, 0.5, 1.0

    lw, sw = lexical_weight / total, semantic_weight / total

    # An item present in one signal but not the other scores zero for the
    # missing one: here both signals ran, so a genuine absence is informative.
    return {
        item_id: lw * lexical.get(item_id, 0.0) + sw * semantic.get(item_id, 0.0)
        for item_id in set(lexical) | set(semantic)
    }


class ConfidenceCalibrator:
    """Maps a fused score onto an estimated probability of being correct.

    Isotonic regression rather than Platt scaling: the relationship between
    fused score and correctness is monotonic but not remotely sigmoid-shaped,
    and isotonic makes no assumption about its form. It needs more data than
    Platt to be stable, which is why ``fit`` refuses to run on a handful of
    examples rather than producing a confident-looking curve from noise.
    """

    #: Below this many labelled examples, a fitted curve would be noise.
    #:
    #: Raised from 50 after measuring what 50 actually buys. Fitted on 62 real
    #: labelled outcomes from a Libyan commodity bulletin — 16 correct, 46 not —
    #: isotonic collapsed into a two-step function: every score landed on either
    #: 0.524 or 1.000. It fixed the noise floor beautifully (keyboard mash went
    #: from 0.582 to 0.000) and simultaneously began auto-resolving olive oil as
    #: cooking oil at 1.000, where it had previously gone to a human. A wrong
    #: price entering the index unreviewed is worse than no calibration at all.
    #:
    #: Isotonic is non-parametric, so it needs enough points to place its steps
    #: somewhere other than between the two clusters it happens to see.
    MIN_SAMPLES = 300

    #: And enough of the rarer outcome to place a boundary at all. 16 positives
    #: among 62 was the other half of that failure: the curve had almost nothing
    #: to learn the "correct" side from.
    MIN_PER_CLASS = 50

    def __init__(self) -> None:
        self._model: IsotonicRegression | None = None
        self._n_samples = 0

    @property
    def is_fitted(self) -> bool:
        return self._model is not None

    @property
    def n_samples(self) -> int:
        return self._n_samples

    def fit(self, scores: list[float], correct: list[bool]) -> bool:
        """Fit from labelled outcomes. Returns whether fitting happened."""
        if len(scores) != len(correct):
            raise ValueError("scores and correct must be the same length.")

        if len(scores) < self.MIN_SAMPLES:
            return False

        # Isotonic needs both classes present; a set that is all-correct or
        # all-wrong carries no information about where the boundary lies. It
        # also needs enough of each: a handful of one class places the boundary
        # wherever those few happen to fall.
        positives = sum(1 for c in correct if c)
        if min(positives, len(correct) - positives) < self.MIN_PER_CLASS:
            return False

        model = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip")
        model.fit(np.asarray(scores, dtype=float), np.asarray(correct, dtype=float))

        self._model = model
        self._n_samples = len(scores)

        return True

    def calibrate(self, score: float) -> float:
        """Turn a fused score into a confidence in [0, 1]."""
        if self._model is None:
            # Uncalibrated fallback. Deliberately conservative: the identity
            # would let an uncalibrated deployment auto-resolve at the same
            # threshold as a calibrated one, which is exactly the false
            # confidence this class exists to prevent. Shrinking towards 0.5
            # keeps early matches below a high auto-resolve threshold until
            # there is evidence to justify them.
            return float(np.clip(0.5 + 0.5 * (score - 0.5) * 1.2, 0.0, 1.0))

        return float(np.clip(self._model.predict([score])[0], 0.0, 1.0))

    def calibrate_all(self, scores: list[float]) -> list[float]:
        return [self.calibrate(s) for s in scores]
