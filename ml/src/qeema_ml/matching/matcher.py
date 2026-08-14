# SPDX-License-Identifier: Apache-2.0
"""The hybrid matcher: lexical + semantic, fused and calibrated."""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
from numpy.typing import NDArray

from qeema_ml.matching.fusion import ConfidenceCalibrator, FusedCandidate, fuse
from qeema_ml.matching.lexical import LexicalIndex
from qeema_ml.matching.normalise import normalise
from qeema_ml.matching.semantic import Embedder, SemanticIndex


@dataclass(frozen=True, slots=True)
class MatchDecision:
    """What the matcher concluded, and what should happen next."""

    candidates: list[FusedCandidate]
    action: str  # auto_resolve | review | reject
    reason: str

    @property
    def best(self) -> FusedCandidate | None:
        return self.candidates[0] if self.candidates else None


@dataclass
class MatcherConfig:
    lexical_weight: float = 0.4
    semantic_weight: float = 0.6
    auto_resolve_threshold: float = 0.85
    review_threshold: float = 0.55
    top_k: int = 5
    #: An exact match after normalisation is trusted regardless of the model.
    exact_match_confidence: float = 0.99


@dataclass
class CatalogueIndexes:
    """Everything needed to match within one country."""

    lexical: LexicalIndex
    semantic: SemanticIndex | None = None
    #: normalised text -> canonical item id, for the exact-match shortcut
    exact: dict[str, tuple[int, str]] = field(default_factory=dict)


class HybridMatcher:
    """Resolves free text to a canonical item.

    The order of operations matters. Exact matching runs first and short-
    circuits, because the single most common case — a reporter picking from the
    catalogue, or typing a name that normalises to a known variant — should
    never depend on a model being loaded, being correctly calibrated, or being
    right.
    """

    def __init__(
        self,
        config: MatcherConfig,
        calibrator: ConfidenceCalibrator | None = None,
        embedder: Embedder | None = None,
    ) -> None:
        self._config = config
        self._calibrator = calibrator or ConfidenceCalibrator()
        self._embedder = embedder

    @property
    def calibrator(self) -> ConfidenceCalibrator:
        return self._calibrator

    def match(self, text: str, catalogue: CatalogueIndexes) -> MatchDecision:
        normalised = normalise(text)
        decided = self._short_circuit(text, normalised, catalogue)

        return decided if decided is not None else self._resolve(normalised, catalogue, None)

    def match_many(self, texts: list[str], catalogue: CatalogueIndexes) -> list[MatchDecision]:
        """Resolve many texts against one catalogue, embedding the queries together.

        The model is markedly more efficient on a batch than on the same texts
        one at a time, because a single forward pass amortises a fixed cost over
        the whole batch. Measured on the shipped model:

            1 text    139 ms      139 ms each
            20 texts  1216 ms      61 ms each
            40 texts  1562 ms      39 ms each

        Resolving them individually — which is what this endpoint used to do —
        cost 84 ms each at twenty, so the loop was throwing away more than half
        the machine. That was the dominant cost of resolving a backlog, not the
        catalogue embedding, which is already cached by fingerprint.

        Only texts that need the model are put in the batch. An exact match on a
        known variant is decided by dictionary lookup and never reaches it,
        which matters more the better the catalogue's vocabulary gets.
        """
        decisions: list[MatchDecision | None] = [None] * len(texts)
        pending: list[int] = []
        normalised: list[str] = []

        for position, text in enumerate(texts):
            candidate = normalise(text)
            decided = self._short_circuit(text, candidate, catalogue)

            if decided is not None:
                decisions[position] = decided
            else:
                pending.append(position)
                normalised.append(candidate)

        vectors = None
        embedder = self._embedder
        semantic = catalogue.semantic

        if pending and embedder is not None and semantic is not None and len(semantic) > 0:
            vectors = embedder.encode_queries(normalised)

        for index, position in enumerate(pending):
            decisions[position] = self._resolve(
                normalised[index],
                catalogue,
                None if vectors is None else vectors[index],
            )

        # Every position was written by one of the two passes above; the cast is
        # for the type checker, not a fallback.
        return [decision for decision in decisions if decision is not None]

    def _short_circuit(
        self, text: str, normalised: str, catalogue: CatalogueIndexes
    ) -> MatchDecision | None:
        """Decide without the model, or return None if the model is needed."""
        if not normalised:
            return MatchDecision([], "reject", "Empty text after normalisation.")

        exact = catalogue.exact.get(normalised)

        if exact is None:
            return None

        item_id, item_code = exact

        return MatchDecision(
            candidates=[
                FusedCandidate(
                    canonical_item_id=item_id,
                    canonical_item_code=item_code,
                    lexical_score=1.0,
                    semantic_score=0.0,
                    fused_score=1.0,
                    confidence=self._config.exact_match_confidence,
                    matched_variant=text,
                )
            ],
            action="auto_resolve",
            reason="Exact match on a known variant after normalisation.",
        )

    def _resolve(
        self,
        normalised: str,
        catalogue: CatalogueIndexes,
        query_vector: NDArray[np.float32] | None,
    ) -> MatchDecision:
        lexical_hits = catalogue.lexical.search(normalised, limit=self._config.top_k * 3)
        lexical_scores = {h.canonical_item_id: h.score for h in lexical_hits}
        variants = {h.canonical_item_id: h.matched_variant for h in lexical_hits}
        codes = {h.canonical_item_id: h.canonical_item_code for h in lexical_hits}

        semantic_scores: dict[int, float] = {}
        semantic_index = catalogue.semantic
        embedder = self._embedder
        semantic_available = (
            semantic_index is not None and embedder is not None and len(semantic_index) > 0
        )

        if semantic_index is not None and embedder is not None and len(semantic_index) > 0:
            if query_vector is None:
                # "query: " prefix on the incoming text — the catalogue side was
                # embedded with "passage: ". Using the same prefix on both sides
                # quietly degrades retrieval.
                query_vector = embedder.encode_queries([normalised])[0]

            for hit in semantic_index.search(query_vector, limit=self._config.top_k * 3):
                semantic_scores[hit.canonical_item_id] = hit.score
                codes.setdefault(hit.canonical_item_id, hit.canonical_item_code)

        fused = fuse(
            lexical_scores,
            semantic_scores,
            self._config.lexical_weight,
            self._config.semantic_weight,
            semantic_available=semantic_available,
        )

        if not fused:
            return MatchDecision([], "reject", "No candidate items found.")

        candidates = [
            FusedCandidate(
                canonical_item_id=item_id,
                canonical_item_code=codes.get(item_id, ""),
                lexical_score=round(lexical_scores.get(item_id, 0.0), 4),
                semantic_score=round(semantic_scores.get(item_id, 0.0), 4),
                fused_score=round(score, 4),
                confidence=round(self._calibrator.calibrate(score), 4),
                matched_variant=variants.get(item_id),
            )
            for item_id, score in sorted(fused.items(), key=lambda kv: kv[1], reverse=True)
        ][: self._config.top_k]

        return MatchDecision(candidates, *self._decide(candidates))

    def _decide(self, candidates: list[FusedCandidate]) -> tuple[str, str]:
        best = candidates[0]

        if best.confidence >= self._config.auto_resolve_threshold:
            # An ambiguous top pair is routed to a human even when confident:
            # two near-identical candidates mean the model cannot tell them
            # apart, and picking the higher one is a coin flip dressed up as a
            # decision.
            if len(candidates) > 1 and (best.fused_score - candidates[1].fused_score) < 0.05:
                return (
                    "review",
                    f"Confident but ambiguous: top two candidates within "
                    f"{best.fused_score - candidates[1].fused_score:.3f}.",
                )

            return ("auto_resolve", f"Confidence {best.confidence:.2f} at or above threshold.")

        if best.confidence >= self._config.review_threshold:
            return ("review", f"Confidence {best.confidence:.2f} below auto-resolve threshold.")

        return ("reject", f"Confidence {best.confidence:.2f} below review threshold.")
