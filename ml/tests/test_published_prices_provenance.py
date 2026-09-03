# SPDX-License-Identifier: Apache-2.0
"""Every hand-collected price file must carry its provenance, row for row.

`countries/imports/<code>/published-prices-*.csv` are observations read from
public pages by a person and imported through the partner path. They are the
only rows in the database that no institution and no reporter stands behind,
so the file beside each one has to: cite a page for every row, quote what it
said, name a configured town, use a configured unit, and carry a date that has
happened. A row missing any of those is a number with no chain back to a source,
which the rest of this platform is built to prevent.
"""

from __future__ import annotations

import csv
import datetime as dt
import json
from pathlib import Path

import pytest
import yaml

REPO = Path(__file__).resolve().parents[2]
IMPORTS = REPO / "countries" / "imports"

FILES = sorted(IMPORTS.glob("*/published-prices-*.csv"))


def _country(code: str) -> dict:
    return yaml.safe_load((REPO / "countries" / f"{code}.yaml").read_text(encoding="utf-8"))


@pytest.mark.parametrize("csv_path", FILES, ids=[str(p.relative_to(IMPORTS)) for p in FILES])
def test_every_row_is_sourced(csv_path: Path) -> None:
    provenance_path = csv_path.with_name(csv_path.name.replace(".csv", ".provenance.json"))
    assert provenance_path.is_file(), f"{csv_path.name} has no provenance file beside it"

    provenance = json.loads(provenance_path.read_text(encoding="utf-8"))
    rows_by_id: dict[str, dict] = provenance["rows"]

    country = _country(csv_path.parent.name)
    slugs = {loc["slug"] for loc in country["locations"]}
    units = {u["code"] for u in country["units"]}
    items = {item["code"] for item in country["canonical_items"]}
    source_slugs = {s["slug"] for s in country["sources"]}

    assert provenance["source_slug"] in source_slugs, (
        f"{provenance_path.name} names source {provenance['source_slug']!r}, "
        "which the country file does not declare"
    )

    with csv_path.open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))

    assert rows, f"{csv_path.name} is empty"
    assert list(rows[0]) == [
        "item",
        "price",
        "location",
        "unit",
        "quantity",
        "date",
        "currency",
        "external_id",
    ]

    today = dt.date.today()

    for row in rows:
        ref = row["external_id"]
        assert ref in rows_by_id, f"{csv_path.name}: no provenance for {ref}"
        entry = rows_by_id[ref]

        # A citation is a page, a quote and a product; a note is optional.
        assert ref.startswith("https://"), f"{ref} is not a URL"
        assert entry.get("quote"), f"{ref}: no verbatim quote"
        assert entry.get("source"), f"{ref}: no source named"
        assert entry.get("item_code") in items, f"{ref}: item {entry.get('item_code')!r} unknown"
        assert entry.get("product_as_written") == row["item"], (
            f"{ref}: the CSV item text must be the product as the source wrote it"
        )

        assert row["location"] in slugs, f"{ref}: location {row['location']!r} not configured"
        assert row["unit"] in units, f"{ref}: unit {row['unit']!r} not configured"
        assert float(row["price"]) > 0
        assert float(row["quantity"]) > 0
        assert dt.date.fromisoformat(row["date"]) <= today, f"{ref}: dated in the future"
        assert row["currency"] == country["country"]["currency"]["code"]

    # The reverse direction: provenance for a row that is not in the file is a
    # citation nobody can check against anything.
    orphans = set(rows_by_id) - {r["external_id"] for r in rows}
    assert not orphans, f"{provenance_path.name} cites rows the CSV lacks: {sorted(orphans)}"


@pytest.mark.parametrize("csv_path", FILES, ids=[str(p.relative_to(IMPORTS)) for p in FILES])
def test_what_was_left_out_says_why(csv_path: Path) -> None:
    provenance_path = csv_path.with_name(csv_path.name.replace(".csv", ".provenance.json"))
    provenance = json.loads(provenance_path.read_text(encoding="utf-8"))

    for entry in provenance.get("not_imported", []):
        assert entry.get("item_code"), "an omission with no item"
        assert len(entry.get("reason", "")) > 40, f"{entry['item_code']}: reason too thin"
