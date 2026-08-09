# SPDX-License-Identifier: Apache-2.0
"""Matching endpoints.

The catalogue is supplied by the caller on every request rather than read from
the database here. That keeps this service genuinely stateless — it can be
scaled, restarted or replaced without any coordination — and it keeps ownership
of the data in one place. Laravel owns the catalogue; this service owns the
opinion about which entry a piece of text refers to.
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from qeema_ml import __version__
from qeema_ml.config import get_settings
from qeema_ml.matching.fusion import ConfidenceCalibrator
from qeema_ml.matching.lexical import LexicalIndex
from qeema_ml.matching.matcher import (
    CatalogueIndexes,
    HybridMatcher,
    MatchDecision,
    MatcherConfig,
)
from qeema_ml.matching.normalise import normalise

router = APIRouter(prefix="/v1", tags=["matching"])

#: Process-wide calibrator, refitted as human review decisions accumulate.
_calibrator = ConfidenceCalibrator()


class VariantIn(BaseModel):
    canonical_item_id: int
    canonical_item_code: str
    text: str
    normalized_text: str | None = None


class CatalogueIn(BaseModel):
    """The catalogue to match against.

    Sent per request. For a country basket — tens of items, a few hundred
    variants — this costs a few kilobytes and buys statelessness.
    """

    variants: list[VariantIn] = Field(default_factory=list)


class MatchRequest(BaseModel):
    text: str = Field(min_length=1, max_length=500)
    catalogue: CatalogueIn
    top_k: int | None = Field(default=None, ge=1, le=50)


class CandidateOut(BaseModel):
    canonical_item_id: int
    canonical_item_code: str
    lexical_score: float
    semantic_score: float
    fused_score: float
    confidence: float
    matched_variant: str | None = None


class MatchResponse(BaseModel):
    normalised_text: str
    action: str = Field(description="auto_resolve | review | reject")
    reason: str
    candidates: list[CandidateOut]
    model_version: str
    calibrated: bool


class BatchMatchRequest(BaseModel):
    texts: list[str] = Field(min_length=1, max_length=500)
    catalogue: CatalogueIn
    top_k: int | None = Field(default=None, ge=1, le=50)


class BatchMatchResponse(BaseModel):
    results: list[MatchResponse]


class CalibrationRequest(BaseModel):
    """Observed outcomes from human review, used to calibrate confidence."""

    scores: list[float] = Field(min_length=1)
    correct: list[bool] = Field(min_length=1)


class CalibrationResponse(BaseModel):
    fitted: bool
    n_samples: int
    reason: str


def _matcher() -> HybridMatcher:
    settings = get_settings()
    lexical_weight, semantic_weight = settings.fusion_weights_normalised

    return HybridMatcher(
        MatcherConfig(
            lexical_weight=lexical_weight,
            semantic_weight=semantic_weight,
            auto_resolve_threshold=settings.match_auto_resolve_threshold,
            review_threshold=settings.match_review_threshold,
            top_k=settings.match_top_k,
        ),
        calibrator=_calibrator,
        embedder=None,
    )


def _build_catalogue(catalogue: CatalogueIn) -> CatalogueIndexes:
    rows = [v.model_dump() for v in catalogue.variants]

    exact: dict[str, tuple[int, str]] = {}
    for variant in catalogue.variants:
        key = normalise(variant.normalized_text or variant.text)
        if key:
            exact.setdefault(key, (variant.canonical_item_id, variant.canonical_item_code))

    return CatalogueIndexes(lexical=LexicalIndex.from_rows(rows), semantic=None, exact=exact)


def _to_response(text: str, decision: MatchDecision, top_k: int | None) -> MatchResponse:
    candidates = decision.candidates[:top_k] if top_k else decision.candidates

    return MatchResponse(
        normalised_text=normalise(text),
        action=decision.action,
        reason=decision.reason,
        candidates=[
            CandidateOut(
                canonical_item_id=c.canonical_item_id,
                canonical_item_code=c.canonical_item_code,
                lexical_score=c.lexical_score,
                semantic_score=c.semantic_score,
                fused_score=c.fused_score,
                confidence=c.confidence,
                matched_variant=c.matched_variant,
            )
            for c in candidates
        ],
        model_version=f"matcher-{__version__}",
        calibrated=_calibrator.is_fitted,
    )


@router.post("/match", response_model=MatchResponse)
def match(request: MatchRequest) -> MatchResponse:
    """Resolve one piece of free text to a canonical item."""
    if not request.catalogue.variants:
        raise HTTPException(status_code=422, detail="Catalogue is empty; nothing to match against.")

    catalogue = _build_catalogue(request.catalogue)
    decision = _matcher().match(request.text, catalogue)

    return _to_response(request.text, decision, request.top_k)


@router.post("/match/batch", response_model=BatchMatchResponse)
def match_batch(request: BatchMatchRequest) -> BatchMatchResponse:
    """Resolve many texts against one catalogue.

    The catalogue is indexed once for the whole batch, which is the entire
    point — rebuilding it per text would dominate the cost of resolving a
    backlog.
    """
    if not request.catalogue.variants:
        raise HTTPException(status_code=422, detail="Catalogue is empty; nothing to match against.")

    catalogue = _build_catalogue(request.catalogue)
    matcher = _matcher()

    return BatchMatchResponse(
        results=[
            _to_response(text, matcher.match(text, catalogue), request.top_k)
            for text in request.texts
        ]
    )


@router.post("/match/calibrate", response_model=CalibrationResponse)
def calibrate(request: CalibrationRequest) -> CalibrationResponse:
    """Fit confidence calibration from human review outcomes.

    Until this has run with enough data, confidence is deliberately shrunk
    towards 0.5 so an uncalibrated deployment does not auto-resolve at the same
    threshold as a calibrated one.
    """
    if len(request.scores) != len(request.correct):
        raise HTTPException(status_code=422, detail="scores and correct must be the same length.")

    fitted = _calibrator.fit(request.scores, request.correct)

    if fitted:
        reason = f"Calibrated on {_calibrator.n_samples} reviewed decisions."
    elif len(request.scores) < ConfidenceCalibrator.MIN_SAMPLES:
        reason = (
            f"Need at least {ConfidenceCalibrator.MIN_SAMPLES} reviewed decisions, "
            f"got {len(request.scores)}."
        )
    else:
        reason = "Needs both correct and incorrect outcomes to locate a boundary."

    return CalibrationResponse(
        fitted=fitted,
        n_samples=_calibrator.n_samples,
        reason=reason,
    )
