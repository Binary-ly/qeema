# SPDX-License-Identifier: Apache-2.0
"""Lexical matching over canonical item names and their known variants.

The lexical half exists because semantic embeddings are surprisingly bad at the
easy cases. A reporter who types the catalogue name with one letter wrong
should match at the top; e5 will happily rank a semantically-related but wrong
product above it, because "infant formula" and "baby cereal" really are close
in meaning. Character-level similarity is what separates them.
"""

from __future__ import annotations

from dataclasses import dataclass

from rapidfuzz import fuzz, process

from qeema_ml.matching.normalise import normalise


@dataclass(frozen=True, slots=True)
class Variant:
    """One known spelling of a canonical item."""

    canonical_item_id: int
    canonical_item_code: str
    text: str
    normalised: str


@dataclass(frozen=True, slots=True)
class LexicalHit:
    canonical_item_id: int
    canonical_item_code: str
    score: float
    matched_variant: str


def score_pair(query: str, candidate: str) -> float:
    """Similarity between two already-normalised strings, in [0, 1].

    ``token_set_ratio`` alone, which is a deliberate choice made by measurement
    rather than intuition. Scored against a set of genuine matches (head-noun
    queries, misspellings, reordered words) and unrelated Arabic pairs:

    ==================  ===========  ==========  =======
    scorer              min(match)   max(other)  margin
    ==================  ===========  ==========  =======
    token_set_ratio          95           40        55
    WRatio                   90           72        18
    token_sort_ratio         35           40        -5
    ratio / QRatio           35           40        -5
    ==================  ===========  ==========  =======

    Combining them with ``max()`` — the obvious first instinct — is strictly
    worse than the best single scorer, because the maximum inherits the *worst*
    false-positive behaviour of its members. ``partial_ratio`` in particular
    scored 0.80 for "ارز" against "بوتاجاز", two entirely unrelated words: a
    three-character query partial-matches almost anything, and Arabic product
    names are short. That would have produced confident wrong auto-resolves.

    The remaining weakness is honest and handled elsewhere: a one-token query
    scores 100 against every candidate containing that token, so "زيت" cannot
    separate two different oils. That ambiguity is real rather than a scoring
    bug, and the matcher routes a too-close top pair to human review.
    """
    if not query or not candidate:
        return 0.0

    return fuzz.token_set_ratio(query, candidate) / 100.0


class LexicalIndex:
    """In-memory index of variants for one country.

    Small by construction — a country's basket is tens of items with a few
    hundred variants — so an exhaustive scan is both fast enough and simpler
    than maintaining a second index alongside Postgres's trigram one.
    """

    def __init__(self, variants: list[Variant]) -> None:
        self._variants = variants
        self._choices = [v.normalised for v in variants]

    @classmethod
    def from_rows(cls, rows: list[dict]) -> LexicalIndex:
        """Build from rows as the API receives them.

        Normalises here rather than trusting the caller: the stored
        ``normalized_text`` should already be correct, but a variant added by a
        route that skipped normalisation would otherwise silently never match.
        """
        variants = [
            Variant(
                canonical_item_id=int(row["canonical_item_id"]),
                canonical_item_code=str(row["canonical_item_code"]),
                text=str(row["text"]),
                normalised=normalise(str(row.get("normalized_text") or row["text"])),
            )
            for row in rows
        ]

        return cls(variants)

    def __len__(self) -> int:
        return len(self._variants)

    def search(self, query: str, limit: int = 10) -> list[LexicalHit]:
        """Best lexical hits for a raw query string.

        Collapsed to one hit per canonical item, keeping its best-scoring
        variant: an item with forty recorded spellings would otherwise occupy
        every slot in the result and crowd out genuine alternatives.
        """
        normalised_query = normalise(query)

        if not normalised_query or not self._choices:
            return []

        raw = process.extract(
            normalised_query,
            self._choices,
            scorer=fuzz.token_set_ratio,
            limit=min(len(self._choices), max(limit * 5, 25)),
        )

        best: dict[int, LexicalHit] = {}

        for _, _, index in raw:
            variant = self._variants[index]
            score = score_pair(normalised_query, variant.normalised)
            current = best.get(variant.canonical_item_id)

            if current is None or score > current.score:
                best[variant.canonical_item_id] = LexicalHit(
                    canonical_item_id=variant.canonical_item_id,
                    canonical_item_code=variant.canonical_item_code,
                    score=score,
                    matched_variant=variant.text,
                )

        return sorted(best.values(), key=lambda h: h.score, reverse=True)[:limit]
