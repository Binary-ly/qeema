# SPDX-License-Identifier: Apache-2.0
"""Matching endpoints.

The catalogue is supplied by the caller on every request rather than read from
the database here. That keeps this service genuinely stateless — it can be
scaled, restarted or replaced without any coordination — and it keeps ownership
of the data in one place. Laravel owns the catalogue; this service owns the
opinion about which entry a piece of text refers to.
"""

from __future__ import annotations

import hashlib
import logging
import threading
from collections import OrderedDict

import numpy as np
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
from qeema_ml.matching.semantic import SemanticIndex, SentenceTransformerEmbedder

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/v1", tags=["matching"])

#: Process-wide calibrator, refitted as human review decisions accumulate.
_calibrator = ConfidenceCalibrator()

#: How many distinct catalogues to keep embedded at once.
#:
#: One per country, and a deployment serves a handful. The bound exists so a
#: caller that varies its catalogue per request cannot grow this without limit.
_INDEX_CACHE_SIZE = 8

_embedder: SentenceTransformerEmbedder | None = None
_embedder_failed = False
_embedder_lock = threading.Lock()

_index_cache: OrderedDict[str, SemanticIndex] = OrderedDict()
_index_lock = threading.Lock()


def _get_embedder() -> SentenceTransformerEmbedder | None:
    """The sentence-transformer, loaded once and shared.

    Loading takes ~20s and about a gigabyte, so it happens on first use behind a
    lock rather than per request. A failure is remembered: if the weights are
    missing or the model cannot be constructed, every later call returns None
    immediately instead of retrying a 20-second failure on every request.

    Returning None is a supported state, not an error path. The matcher scores
    lexically and — importantly — renormalises its fusion weights over the
    signals that actually ran, so an absent semantic score is treated as absent
    evidence rather than as evidence of a poor match.
    """
    global _embedder, _embedder_failed

    if _embedder is not None or _embedder_failed:
        return _embedder

    with _embedder_lock:
        # Re-check: another thread may have loaded it while this one waited.
        if _embedder is not None or _embedder_failed:
            return _embedder

        settings = get_settings()

        if not settings.load_models_on_startup:
            # Unit tests must not pull a 1.1 GB model.
            _embedder_failed = True

            return None

        try:
            # Imported here, not at module scope: importing sentence_transformers
            # pulls in torch, and the unit test run deliberately never loads
            # weights. A top-level import would cost every test process a
            # gigabyte to reach code that does not use it.
            from sentence_transformers import SentenceTransformer

            _embedder = SentenceTransformerEmbedder(
                model=SentenceTransformer(settings.embedding_model),
                query_prefix=settings.embedding_query_prefix,
                passage_prefix=settings.embedding_passage_prefix,
                batch_size=settings.embedding_batch_size,
            )
        except Exception:  # pragma: no cover - depends on the deployed image
            logger.exception("Embedding model unavailable; matching stays lexical.")
            _embedder_failed = True

    return _embedder


def _catalogue_fingerprint(catalogue: CatalogueIn) -> str:
    """A stable key for one exact catalogue.

    Hashed over the text and item id of every variant in order, so any edit —
    an added variant, a corrected spelling — produces a different key and the
    stale index is never served. Cheap next to embedding the catalogue.
    """
    digest = hashlib.blake2b(digest_size=16)

    for variant in catalogue.variants:
        digest.update(str(variant.canonical_item_id).encode())
        digest.update(b"\x00")
        digest.update((variant.normalized_text or variant.text).encode())
        digest.update(b"\x01")

    return digest.hexdigest()


def _semantic_index(catalogue: CatalogueIn) -> SemanticIndex | None:
    """Embed a catalogue, or return the cached embedding of it.

    Embedding is the expensive part of matching — hundreds of milliseconds for a
    typical catalogue — and the catalogue changes far less often than requests
    arrive. Without this cache every single match would pay the full cost, which
    is why the semantic path was originally left switched off.
    """
    embedder = _get_embedder()

    if embedder is None or not catalogue.variants:
        return None

    key = _catalogue_fingerprint(catalogue)

    with _index_lock:
        cached = _index_cache.get(key)

        if cached is not None:
            _index_cache.move_to_end(key)

            return cached

    # Deliberately outside the lock: embedding is slow, and holding the lock
    # would serialise every request behind the first one.
    texts = [v.normalized_text or v.text for v in catalogue.variants]

    try:
        vectors = embedder.encode_passages(texts)
    except Exception:  # pragma: no cover - depends on the deployed image
        logger.exception("Embedding the catalogue failed; matching stays lexical.")

        return None

    index = SemanticIndex(
        item_ids=[v.canonical_item_id for v in catalogue.variants],
        item_codes=[v.canonical_item_code for v in catalogue.variants],
        embeddings=np.asarray(vectors, dtype=np.float32),
    )

    with _index_lock:
        _index_cache[key] = index
        _index_cache.move_to_end(key)

        while len(_index_cache) > _INDEX_CACHE_SIZE:
            _index_cache.popitem(last=False)

    return index


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
        embedder=_get_embedder(),
    )


def _build_catalogue(catalogue: CatalogueIn) -> CatalogueIndexes:
    rows = [v.model_dump() for v in catalogue.variants]

    exact: dict[str, tuple[int, str]] = {}
    for variant in catalogue.variants:
        key = normalise(variant.normalized_text or variant.text)
        if key:
            exact.setdefault(key, (variant.canonical_item_id, variant.canonical_item_code))

    return CatalogueIndexes(
        lexical=LexicalIndex.from_rows(rows),
        semantic=_semantic_index(catalogue),
        exact=exact,
    )


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

    The catalogue is indexed once for the whole batch, and the queries are
    embedded in one forward pass rather than one per text. Both matter: the
    first avoids rebuilding an index per submission, and the second was worth
    more than half the wall clock once catalogues grew past a few hundred
    variants. See ``HybridMatcher.match_many`` for the measurements.
    """
    if not request.catalogue.variants:
        raise HTTPException(status_code=422, detail="Catalogue is empty; nothing to match against.")

    catalogue = _build_catalogue(request.catalogue)
    matcher = _matcher()

    decisions = matcher.match_many(request.texts, catalogue)

    return BatchMatchResponse(
        results=[
            _to_response(text, decision, request.top_k)
            for text, decision in zip(request.texts, decisions, strict=True)
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
