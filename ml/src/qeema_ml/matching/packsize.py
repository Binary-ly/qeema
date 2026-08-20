# SPDX-License-Identifier: Apache-2.0
"""Disambiguating look-alike items by the size the reporter stated.

**The failure this exists for.** Three quarters of the matcher's errors were
sibling pairs sharing a head noun, and the sharpest was flour: `wheat_flour_1kg`
against `bakery_flour_50kg`. A 50 kg trade sack and a 1 kg household bag are the
same words apart from a number, and they differ in price by roughly sixty times.
The embedder cannot see the difference because to it `50` is one more token.

But the reporter often *tells* us — a sack wording carrying "50 kg", or the same
number glued to the word. When a query states a size, that size is hard evidence
about which item is meant, and the catalogue already knows what each item holds.

**Why it did not work the first time.** Measured against `default_quantity`, the
signal picked the correct sibling only 33 times out of 52 — worse than useless,
because it would have introduced nearly as many errors as it removed. The cause
was not the idea. Seven items declare `1 pack` while their code name states a
real size: `tomato_paste_400g` is `1 pack`, `canned_tuna_185g` is `1 pack`. The
size lived in the code *string* and not in the data, so there was nothing to
compare against. With a `pack_size` field carrying the real content the same
measurement gives 45 out of 45.

`default_quantity` is deliberately left alone: it drives basket costing, and the
last time a quantity moved without its unit the published figure was out by a
thousand.

**Country-agnostic.** Every unit word comes from the country file's
`units[].aliases`. Nothing here knows what language it is reading.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from .normalise import normalise

#: Sizes within this ratio of each other are treated as the same pack. Shops
#: routinely sell 400 g and 500 g of the same staple as one product, and price
#: bulletins publish them as a single series, so the band has to be wider than a
#: strict reading of the item code would suggest.
AGREEMENT_RATIO = 1.4

#: Beyond this the two cannot be the same product: a 25 kg sack is not a 1 kg
#: bag. Between the two bands the evidence is judged too weak to act on.
CONTRADICTION_RATIO = 3.0

#: Added to a candidate's fused score when the stated size matches its pack,
#: subtracted when it contradicts. The penalty is larger because a contradiction
#: is stronger evidence than an agreement: many products share a size, but a
#: product cannot hold two different amounts.
AGREEMENT_BONUS = 0.05
CONTRADICTION_PENALTY = 0.12

_LETTER_DIGIT = re.compile(r"(?<=[^\W\d_])(?=\d)|(?<=\d)(?=[^\W\d_])", re.UNICODE)


@dataclass(frozen=True)
class UnitTable:
    """One country's unit vocabulary and conversions, taken from its config."""

    factor: dict[str, float]
    base: dict[str, str]
    alias_to_code: dict[str, str]
    pattern: re.Pattern[str] | None

    @classmethod
    def from_units(cls, units: list[dict]) -> UnitTable:
        factor: dict[str, float] = {}
        base: dict[str, str] = {}
        alias_to_code: dict[str, str] = {}

        for unit in units:
            code = str(unit["code"])
            factor[code] = float(unit.get("factor_to_base", 1.0))
            base[code] = str(unit.get("base_unit_code", code))

            aliases = [str(a) for a in (unit.get("aliases") or [])]
            for alias in [*aliases, code, str(unit.get("name_local") or "")]:
                key = normalise(alias)
                if key:
                    alias_to_code.setdefault(key, code)

        pattern = None
        if alias_to_code:
            # Longest first, so "كيلوغرام" is not consumed as "كيلو" plus junk.
            alts = sorted(alias_to_code, key=len, reverse=True)
            pattern = re.compile(
                r"(\d+(?:[.,]\d+)?)\s*(" + "|".join(re.escape(a) for a in alts) + r")(?!\w)",
                re.UNICODE,
            )

        return cls(factor=factor, base=base, alias_to_code=alias_to_code, pattern=pattern)

    def to_base(self, quantity: float, unit_code: str) -> tuple[float, str] | None:
        if unit_code not in self.factor:
            return None

        return quantity * self.factor[unit_code], self.base[unit_code]


def parse_sizes(text: str, table: UnitTable) -> list[tuple[float, str]]:
    """Every explicit size in the text, converted to its base unit.

    Sellers glue the number to the unit word with no space, so the digits are
    split from the letters here. That split is local to this parser; the
    shared normaliser is not touched, because it also decides database keys and
    has a PHP twin pinned to the same fixtures.
    """
    if table.pattern is None:
        return []

    spaced = _LETTER_DIGIT.sub(" ", normalise(text))
    sizes: list[tuple[float, str]] = []

    for raw, alias in table.pattern.findall(spaced):
        code = table.alias_to_code.get(alias)
        if code is None:
            continue

        converted = table.to_base(float(raw.replace(",", ".")), code)
        if converted is not None:
            sizes.append(converted)

    return sizes


def adjustment(stated: list[tuple[float, str]], pack: tuple[float, str] | None) -> float:
    """How much to move a candidate's score, given what the query said.

    Zero whenever the evidence does not apply: no size stated, no pack declared,
    or a size measured in a dimension the item is not sold in. Silence is the
    right answer far more often than a guess is.
    """
    if not stated or pack is None:
        return 0.0

    pack_quantity, pack_base = pack
    if pack_quantity <= 0.0:
        return 0.0

    comparable = [q for q, unit in stated if unit == pack_base and q > 0.0]
    if not comparable:
        return 0.0

    # The closest stated size decides. A string may carry several numbers and
    # only one of them is the pack ("عرض 3 قطع، 400 جرام").
    ratio = min(max(q, pack_quantity) / min(q, pack_quantity) for q in comparable)

    if ratio <= AGREEMENT_RATIO:
        return AGREEMENT_BONUS

    if ratio >= CONTRADICTION_RATIO:
        return -CONTRADICTION_PENALTY

    return 0.0
