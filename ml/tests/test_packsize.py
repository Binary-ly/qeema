# SPDX-License-Identifier: Apache-2.0
"""A stated size is the only thing that separates a sack from a bag.

The matcher's largest single error class was `wheat_flour_1kg` against
`bakery_flour_50kg` — same words, different number, sixty times the price. These
tests pin the evidence that separates them, and the two ways the mechanism has
already been got wrong: comparing against a pack the item never declared, and
failing to read a size the seller glued to the unit word.
"""

from __future__ import annotations

import pytest

from qeema_ml.matching.packsize import (
    AGREEMENT_BONUS,
    CONTRADICTION_PENALTY,
    UnitTable,
    adjustment,
    parse_sizes,
)

UNITS = [
    {"code": "kg", "base_unit_code": "kg", "factor_to_base": 1, "aliases": ["kilo", "kg"]},
    {"code": "g", "base_unit_code": "kg", "factor_to_base": 0.001, "aliases": ["gram", "g"]},
    {"code": "l", "base_unit_code": "l", "factor_to_base": 1, "aliases": ["litre", "l"]},
    {"code": "ml", "base_unit_code": "l", "factor_to_base": 0.001, "aliases": ["ml"]},
    {"code": "piece", "base_unit_code": "piece", "factor_to_base": 1, "aliases": ["piece"]},
]


@pytest.fixture
def table() -> UnitTable:
    return UnitTable.from_units(UNITS)


def test_reads_a_spaced_size(table: UnitTable) -> None:
    assert parse_sizes("flour 50 kg", table) == [(50.0, "kg")]


def test_reads_a_size_glued_to_the_unit_word(table: UnitTable) -> None:
    # Sellers write "50kg" with no space. Left unsplit this is a single token
    # that matches nothing, which is how the sack cases were being lost.
    assert parse_sizes("flour 50kg", table) == [(50.0, "kg")]


def test_converts_to_base_units(table: UnitTable) -> None:
    assert parse_sizes("tin 400 gram", table) == [(0.4, "kg")]
    assert parse_sizes("bottle 60 ml", table) == [(0.06, "l")]


def test_prefers_the_longer_alias(table: UnitTable) -> None:
    # "litre" must not be consumed as "l" plus leftovers.
    assert parse_sizes("oil 1 litre", table) == [(1.0, "l")]


def test_reads_nothing_when_no_size_is_stated(table: UnitTable) -> None:
    assert parse_sizes("tomato paste brand", table) == []


def test_a_bare_number_is_not_a_size(table: UnitTable) -> None:
    # A price or a stage number is not a pack size.
    assert parse_sizes("formula stage 2", table) == []


def test_agreement_is_rewarded(table: UnitTable) -> None:
    assert adjustment([(0.4, "kg")], (0.4, "kg")) == AGREEMENT_BONUS


def test_a_wide_size_band_still_counts_as_agreement(table: UnitTable) -> None:
    # 400 g and 500 g of the same staple are one product on a price bulletin.
    assert adjustment([(0.4, "kg")], (0.5, "kg")) == AGREEMENT_BONUS


def test_contradiction_is_penalised(table: UnitTable) -> None:
    # The regression this module exists for: a 50 kg sack is not a 1 kg bag.
    assert adjustment([(50.0, "kg")], (1.0, "kg")) == -CONTRADICTION_PENALTY


def test_the_sack_beats_the_bag(table: UnitTable) -> None:
    stated = parse_sizes("sack of flour 50kg", table)
    sack = adjustment(stated, (50.0, "kg"))
    bag = adjustment(stated, (1.0, "kg"))
    assert sack > bag


def test_middling_differences_are_left_alone(table: UnitTable) -> None:
    # Between the bands the evidence is too weak to act on, and guessing here
    # would introduce errors rather than remove them.
    assert adjustment([(2.0, "kg")], (1.0, "kg")) == 0.0


def test_a_size_in_another_dimension_is_ignored(table: UnitTable) -> None:
    # Litres say nothing about an item sold by weight.
    assert adjustment([(1.0, "l")], (1.0, "kg")) == 0.0


def test_silent_when_the_item_declares_no_pack(table: UnitTable) -> None:
    assert adjustment([(1.0, "kg")], None) == 0.0


def test_silent_when_nothing_was_stated(table: UnitTable) -> None:
    assert adjustment([], (1.0, "kg")) == 0.0


def test_a_non_positive_pack_is_ignored(table: UnitTable) -> None:
    assert adjustment([(1.0, "kg")], (0.0, "kg")) == 0.0


def test_the_closest_stated_size_decides(table: UnitTable) -> None:
    # "3 pieces, 400 gram" carries two numbers and only one is the pack.
    assert adjustment([(3.0, "piece"), (0.4, "kg")], (0.4, "kg")) == AGREEMENT_BONUS


def test_an_empty_unit_table_reads_nothing() -> None:
    assert parse_sizes("flour 50 kg", UnitTable.from_units([])) == []


def test_unit_code_itself_works_as_an_alias() -> None:
    # A country file that lists no aliases still gets the codes for free.
    bare = UnitTable.from_units([{"code": "kg", "base_unit_code": "kg", "factor_to_base": 1}])
    assert parse_sizes("flour 50 kg", bare) == [(50.0, "kg")]


def test_to_base_rejects_an_unknown_unit(table: UnitTable) -> None:
    assert table.to_base(1.0, "furlong") is None


# ---------------------------------------------------------------------------
# End to end, through the API the service actually exposes
# ---------------------------------------------------------------------------
# The signal is worthless if it only exists in the evaluation harness. Laravel
# owns the catalogue and sends it per request, so the size table has to survive
# the wire: schema, request body, index construction and cache key.


def _flour_catalogue() -> dict:
    """Two items that differ only by how much they hold.

    Neither owns the bare word "flour". That is not a convenience: a word naming
    two basket items must not be the exclusive property of one, and a catalogue
    that breaks the rule is caught by
    api/tests/Feature/Country/CatalogueVariantPlacementTest.php. Writing the
    fixture the other way makes the lexical score on the bare noun swamp every
    other signal, which is the bug that rule exists to prevent.
    """
    return {
        "variants": [
            {"canonical_item_id": 1, "canonical_item_code": "flour_1kg", "text": "wheat flour bag"},
            {"canonical_item_id": 1, "canonical_item_code": "flour_1kg", "text": "household flour"},
            {"canonical_item_id": 2, "canonical_item_code": "flour_50kg", "text": "bakery flour"},
            {"canonical_item_id": 2, "canonical_item_code": "flour_50kg", "text": "flour sack"},
        ],
        "units": [
            {
                "code": "kg",
                "base_unit_code": "kg",
                "factor_to_base": 1.0,
                "aliases": ["kilo", "kg"],
            },
        ],
        "packs": [
            {"canonical_item_id": 1, "quantity": 1.0, "unit_code": "kg"},
            {"canonical_item_id": 2, "quantity": 50.0, "unit_code": "kg"},
        ],
    }


def test_a_stated_sack_size_reaches_the_service(ready_client) -> None:  # type: ignore[no-untyped-def]
    body = {"text": "flour 50kg", "catalogue": _flour_catalogue(), "top_k": 2}
    response = ready_client.post("/v1/match", json=body)

    assert response.status_code == 200
    assert response.json()["candidates"][0]["canonical_item_code"] == "flour_50kg"


def test_the_same_words_without_a_size_are_not_forced(ready_client) -> None:  # type: ignore[no-untyped-def]
    # No size stated, so the size table must stay silent rather than invent a
    # preference. Whatever the text scorers decide is left alone.
    catalogue = _flour_catalogue()
    with_sizes = ready_client.post(
        "/v1/match", json={"text": "bakery flour", "catalogue": catalogue, "top_k": 2}
    ).json()

    without = {k: v for k, v in catalogue.items() if k not in {"units", "packs"}}
    plain = ready_client.post(
        "/v1/match", json={"text": "bakery flour", "catalogue": without, "top_k": 2}
    ).json()

    assert (
        with_sizes["candidates"][0]["canonical_item_code"]
        == (plain["candidates"][0]["canonical_item_code"])
    )


def test_a_caller_that_sends_no_size_table_still_works(ready_client) -> None:  # type: ignore[no-untyped-def]
    # An older Laravel deployment must not break against a newer service.
    catalogue = {k: v for k, v in _flour_catalogue().items() if k == "variants"}
    response = ready_client.post(
        "/v1/match", json={"text": "flour 50kg", "catalogue": catalogue, "top_k": 2}
    )

    assert response.status_code == 200
