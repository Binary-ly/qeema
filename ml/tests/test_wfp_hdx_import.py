# SPDX-License-Identifier: Apache-2.0
"""The HDX transform is the record of what a WFP import selected.

The first import of WFP's Libya file was done with a throwaway script that
took seven commodities and left out eggs, and nothing recorded the selection,
so the omission was invisible until somebody counted basket items against the
file four months later. The committed script exists so the selection is
written down; these tests hold it to the three things that matter: the rows it
writes are what the importer accepts, the rows it drops are the ones it says
it drops, and an observation already in the database is not written twice.
"""

from __future__ import annotations

import csv
import json
import subprocess
import sys
from pathlib import Path

import pytest

SCRIPT = Path(__file__).resolve().parents[2] / "infra" / "scripts" / "wfp-hdx-import.py"

HEADER = (
    "date,admin1,admin2,market,market_id,latitude,longitude,category,commodity,"
    "commodity_id,unit,priceflag,pricetype,currency,price,usdprice"
)


def _row(
    date: str,
    market: str,
    commodity: str,
    unit: str,
    price: str,
    *,
    market_id: str = "1",
    commodity_id: str = "9",
    flag: str = "actual",
) -> str:
    return (
        f"{date},Region,District,{market},{market_id},30.0,15.0,category,{commodity},"
        f"{commodity_id},{unit},{flag},Retail,XXX,{price},1.0"
    )


@pytest.fixture
def mapping(tmp_path: Path) -> Path:
    path = tmp_path / "map.json"
    path.write_text(
        json.dumps(
            {
                "source_slug": "test-source",
                "currency": "XXX",
                "markets": {"North market": "north", "South market": "south"},
                "commodities": {
                    "Eggs": {"code": "eggs_30", "unit": "piece", "quantity": 30},
                    "Rice": {"code": "rice_1kg", "unit": "kg", "quantity": 1},
                },
                "stale": {},
            }
        ),
        encoding="utf-8",
    )
    return path


@pytest.fixture
def download(tmp_path: Path) -> Path:
    path = tmp_path / "wfp.csv"
    lines = [
        HEADER,
        # An HXL tag row, which HDX files may carry under the header.
        "#date,#adm1,#adm2,#loc,#loc+code,#geo+lat,#geo+lon,#item+type,#item,#item+code,"
        "#item+unit,#item+price+flag,#item+price+type,#currency,#value,#value+usd",
        _row("2026-04-15", "North market", "Eggs", "30 pcs", "24.5", commodity_id="92"),
        _row("2026-05-15", "North market", "Eggs", "30 pcs", "25.5", commodity_id="92"),
        _row(
            "2026-05-15", "South market", "Eggs", "30 pcs", "27", market_id="2", commodity_id="92"
        ),
        _row("2026-05-15", "Unmapped market", "Eggs", "30 pcs", "26", market_id="3"),
        _row("2026-05-15", "North market", "Rice", "KG", "6.12"),
        _row("2026-05-15", "North market", "Onions", "KG", "6"),
        _row("2023-12-15", "North market", "Eggs", "30 pcs", "19"),
        _row("2026-05-15", "South market", "Rice", "KG", "6.5", market_id="2", flag="forecast"),
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def run(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(SCRIPT), *args],
        capture_output=True,
        text=True,
        check=False,
    )


def read(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def test_writes_rows_the_importer_accepts(mapping: Path, download: Path, tmp_path: Path) -> None:
    out = tmp_path / "out.csv"
    result = run("--csv", str(download), "--map", str(mapping), "--out", str(out))

    assert result.returncode == 0, result.stderr
    rows = read(out)

    # Every column is one of the importer's own header aliases, so the
    # operator confirms a guessed mapping rather than typing one.
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

    eggs = [r for r in rows if r["item"].startswith("Eggs")]
    assert {r["location"] for r in eggs} == {"north", "south"}
    april = next(r for r in eggs if r["location"] == "north" and r["date"] == "2026-04-15")
    # The item text is WFP's bare commodity name, which exact-matches the
    # catalogue; with the unit string appended it scored 0.77 and went to
    # review. The unit and quantity travel in their own columns.
    assert april["item"] == "Eggs"
    assert april["price"] == "24.5"
    assert april["unit"] == "piece"
    assert april["quantity"] == "30"
    assert april["currency"] == "XXX"
    assert april["external_id"] == "wfp-hdx:92:1:2026-04-15"


def test_drops_what_it_says_it_drops(mapping: Path, download: Path, tmp_path: Path) -> None:
    out = tmp_path / "out.csv"
    result = run(
        "--csv",
        str(download),
        "--map",
        str(mapping),
        "--since",
        "2024-01-01",
        "--out",
        str(out),
    )

    assert result.returncode == 0, result.stderr
    rows = read(out)

    # The unmapped market is dropped rather than forced onto a neighbour, the
    # unmapped commodity is dropped, the pre-`since` row is dropped, and the
    # one row not flagged `actual` is dropped: an estimate imported as an
    # observation is the one thing the platform promises never to do.
    assert len(rows) == 4
    assert all(r["location"] in {"north", "south"} for r in rows)
    assert not any(r["item"].startswith("Onions") for r in rows)
    assert not any(r["date"] < "2024-01-01" for r in rows)
    assert not any(r["item"].startswith("Rice") and r["location"] == "south" for r in rows)
    assert "unmapped_market=1" in result.stdout
    assert "not_actual=1" in result.stdout


def test_commodity_filter_and_exclusion(mapping: Path, download: Path, tmp_path: Path) -> None:
    already = tmp_path / "already.csv"
    # The shape the database export has: code, location slug, observed date.
    already.write_text("eggs_30,north,2026-04-15,24.5\n", encoding="utf-8")

    out = tmp_path / "out.csv"
    result = run(
        "--csv",
        str(download),
        "--map",
        str(mapping),
        "--commodity",
        "Eggs",
        "--exclude",
        str(already),
        "--out",
        str(out),
    )

    assert result.returncode == 0, result.stderr
    rows = read(out)

    assert all(r["item"].startswith("Eggs") for r in rows)
    # The April row is in the database already; two different files carrying
    # the same observation would both be accepted by the importer, so this is
    # the only guard against publishing it twice.
    assert {(r["location"], r["date"]) for r in rows} == {
        ("north", "2026-05-15"),
        ("south", "2026-05-15"),
        ("north", "2023-12-15"),
    }
    assert "already=1" in result.stdout


def test_refuses_a_commodity_the_mapping_does_not_know(
    mapping: Path, download: Path, tmp_path: Path
) -> None:
    result = run(
        "--csv",
        str(download),
        "--map",
        str(mapping),
        "--commodity",
        "Diapers",
        "--out",
        str(tmp_path / "out.csv"),
    )

    assert result.returncode == 2
    assert "Diapers" in result.stderr
    assert not (tmp_path / "out.csv").exists()
