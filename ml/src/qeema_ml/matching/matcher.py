# SPDX-License-Identifier: Apache-2.0
"""The hybrid matcher: lexical + semantic, fused and calibrated."""

from __future__ import annotations

from dataclasses import dataclass, field

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

        if not normalised:
            return MatchDecision([], "reject", "Empty text after normalisation.")

        exact = catalogue.exact.get(normalised)
        if exact is not None:
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
