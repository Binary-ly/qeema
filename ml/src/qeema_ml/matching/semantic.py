# SPDX-License-Identifier: Apache-2.0
"""Semantic matching with multilingual-e5.

The semantic half exists for the cases lexical matching cannot reach: a
reporter writing "baby milk" in English against an Arabic catalogue entry, or
"بوتاجاز" against "أسطوانة غاز". No amount of character similarity connects
those; a multilingual embedding does.

Two details are load-bearing and easy to get wrong.

**The e5 prefixes.** These models are trained asymmetrically: the incoming
query gets ``query: `` and the indexed text gets ``passage: ``. Omitting them,
or using the same prefix on both sides, measurably degrades retrieval — and
degrades it *quietly*, since the scores still look plausible.

**Normalisation to unit length.** Cosine similarity on unnormalised vectors is
not comparable across items, so a fixed confidence threshold would mean
different things for different products.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import TYPE_CHECKING, Any, Protocol

import numpy as np

if TYPE_CHECKING:
    from numpy.typing import NDArray


class Embedder(Protocol):
    """Anything that can turn text into vectors.

    A protocol rather than a concrete class so tests can substitute a
    deterministic stand-in instead of loading 1.1 GB of weights.
    """

    def encode_queries(self, texts: list[str]) -> NDArray[np.float32]: ...

    def encode_passages(self, texts: list[str]) -> NDArray[np.float32]: ...

    @property
    def dimension(self) -> int: ...


@dataclass(frozen=True, slots=True)
class SemanticHit:
    canonical_item_id: int
    canonical_item_code: str
    score: float


class SentenceTransformerEmbedder:
    """Embedder backed by a local sentence-transformers model.

    Weights are loaded from the image, never downloaded at runtime — the
    container sets ``HF_HUB_OFFLINE=1`` precisely so that a missing model fails
    loudly at boot rather than silently reaching for the network in a
    deployment that has none.
    """

    def __init__(
        self,
        # Typed as Any: importing SentenceTransformer here would pull torch
        # into every process that merely imports this module, including the
        # unit test run that deliberately never loads weights.
        model: Any,
        query_prefix: str,
        passage_prefix: str,
        batch_size: int = 32,
    ) -> None:
        self._model = model
        self._query_prefix = query_prefix
        self._passage_prefix = passage_prefix
        self._batch_size = batch_size

    @property
    def dimension(self) -> int:
        return int(self._model.get_sentence_embedding_dimension())

    def encode_queries(self, texts: list[str]) -> NDArray[np.float32]:
        return self._encode(texts, self._query_prefix)

    def encode_passages(self, texts: list[str]) -> NDArray[np.float32]:
        return self._encode(texts, self._passage_prefix)

    def _encode(self, texts: list[str], prefix: str) -> NDArray[np.float32]:
        if not texts:
            return np.zeros((0, self.dimension), dtype=np.float32)

        prefixed = [f"{prefix}{text}" for text in texts]

        vectors = self._model.encode(
            prefixed,
            batch_size=self._batch_size,
            normalize_embeddings=True,
            convert_to_numpy=True,
            show_progress_bar=False,
        )

        return np.asarray(vectors, dtype=np.float32)


class SemanticIndex:
    """Canonical item embeddings, searched by cosine similarity.

    A country's catalogue is tens of items, so an exhaustive matrix product is
    both exact and faster than any approximate index would be. pgvector's HNSW
    index earns its place on the database side, where the table is queried
    directly; here the whole catalogue fits in a single small array.
    """

    def __init__(
        self,
        item_ids: list[int],
        item_codes: list[str],
        embeddings: NDArray[np.float32],
    ) -> None:
        if len(item_ids) != embeddings.shape[0]:
            raise ValueError(f"Got {len(item_ids)} item ids for {embeddings.shape[0]} embeddings.")

        self._item_ids = item_ids
        self._item_codes = item_codes
        self._embeddings = self._unit_normalise(embeddings)

    @staticmethod
    def _unit_normalise(vectors: NDArray[np.float32]) -> NDArray[np.float32]:
        if vectors.size == 0:
            return vectors

        norms = np.linalg.norm(vectors, axis=1, keepdims=True)
        # Guard against a zero vector, which an unembedded item would produce
        # and which would otherwise yield NaN scores for every query.
        norms[norms == 0] = 1.0

        return (vectors / norms).astype(np.float32)

    def __len__(self) -> int:
        return len(self._item_ids)

    def search(self, query_vector: NDArray[np.float32], limit: int = 10) -> list[SemanticHit]:
        if len(self) == 0:
            return []

        query = np.asarray(query_vector, dtype=np.float32).reshape(-1)
        norm = float(np.linalg.norm(query))

        if norm == 0.0:
            return []

        query = query / norm

        # Cosine similarity, both sides unit length. Clipped because floating
        # point can nudge an identical vector fractionally past 1.0, which
        # would then look like an impossible confidence.
        scores = np.clip(self._embeddings @ query, -1.0, 1.0)

        top = np.argsort(-scores)[:limit]

        return [
            SemanticHit(
                canonical_item_id=self._item_ids[int(i)],
                canonical_item_code=self._item_codes[int(i)],
                score=float(scores[int(i)]),
            )
            for i in top
        ]
