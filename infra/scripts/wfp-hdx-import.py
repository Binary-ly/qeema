#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Turn a WFP food-price CSV from HDX into a file PartnerFileImporter accepts.

    infra/scripts/wfp-hdx-import.py \
        --csv wfp_food_prices_lby.csv \
        --map countries/imports/ly/wfp-hdx.json \
        --since 2024-04-01 \
        --commodity Eggs \
        --exclude already-imported.csv \
        --out countries/imports/ly/wfp-hdx-eggs.csv

Why a script and not the scheduled scraper: HDX's robots.txt disallows
automated fetches of its CSVs, and the platform's scraper honours that. The
licence (CC BY-IGO 3.0) permits reuse, so the file is fetched by a person and
imported through the partner path with attribution — the distinction
docs/data-sources.md draws between crawling and reuse.

Why a script and not a hand edit: the first import of this file, on
1 September 2026, was done with a throwaway transformation that imported seven
commodities and silently left out eggs — a basket item WFP has surveyed every
month since 2017. Nobody could tell, because nothing recorded what the
transformation had selected. This script records it: the mapping file names
every series taken, and `--commodity` makes the selection explicit.

Everything about the country — market spellings, which series price which
item, the unit and quantity each needs — comes from the mapping file
(constraint C3). The script only knows WFP's column names.

Output columns are the importer's aliases, so `ColumnMapping::guess()` maps
them with no operator input: item, price, location, unit, quantity, date,
currency, external_id. The item text is WFP's own commodity name and nothing
else; the matcher, not this script, decides what it is. Measured before this
was settled: the bare names exact-match the catalogue at 0.99, while the same
names with WFP's unit string appended ("Eggs (30 pcs)") score 0.74–0.77 and
every row goes to a human. The unit travels in its own column, where the
importer reads it.

`--exclude` takes a CSV of `code,location_slug,observed_on[,…]` rows that are
already in the database, and drops matching rows. The importer's idempotency
is per file and per row; two different files carrying the same observation
would both be accepted, and this is the guard against that.
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n", maxsplit=1)[0])
    parser.add_argument("--csv", required=True, type=Path, help="the HDX download")
    parser.add_argument("--map", required=True, type=Path, help="countries/imports/<code>/wfp-hdx.json")
    parser.add_argument("--out", required=True, type=Path)
    parser.add_argument("--since", default=None, help="keep rows dated on or after this ISO date")
    parser.add_argument("--until", default=None, help="keep rows dated on or before this ISO date")
    parser.add_argument(
        "--commodity",
        action="append",
        default=[],
        help="WFP commodity name to include; repeatable; default is every mapped series",
    )
    parser.add_argument(
        "--exclude",
        type=Path,
        default=None,
        help="CSV of code,location_slug,observed_on rows already imported",
    )
    args = parser.parse_args()

    mapping = json.loads(args.map.read_text(encoding="utf-8"))
    markets: dict[str, str] = mapping["markets"]
    commodities: dict[str, dict] = mapping["commodities"]
    currency: str = mapping["currency"]

    wanted = set(args.commodity) or set(commodities)
    unknown = wanted - set(commodities)
    if unknown:
        print(f"not in the mapping file: {sorted(unknown)}", file=sys.stderr)
        return 2

    already: set[tuple[str, str, str]] = set()
    if args.exclude is not None:
        with args.exclude.open(encoding="utf-8", newline="") as handle:
            for row in csv.reader(handle):
                if len(row) >= 3:
                    already.add((row[0], row[1], row[2]))

    kept: list[dict[str, str]] = []
    counts = {"rows": 0, "unmapped_market": 0, "other_commodity": 0, "out_of_range": 0, "not_actual": 0, "already": 0}

    with args.csv.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            counts["rows"] += 1
            date = row.get("date", "")
            if not date[:1].isdigit():
                continue  # HXL tag row or blank

            commodity = row["commodity"]
            if commodity not in wanted:
                counts["other_commodity"] += 1
                continue

            slug = markets.get(row["market"])
            if slug is None:
                counts["unmapped_market"] += 1
                continue

            if (args.since and date < args.since) or (args.until and date > args.until):
                counts["out_of_range"] += 1
                continue

            # Every row in the Libyan file is `actual` / `Retail`; the check
            # is here for the day WFP adds an estimated series, because an
            # estimate imported as an observation is the one thing this
            # platform promises never to do.
            if row.get("priceflag", "actual") != "actual":
                counts["not_actual"] += 1
                continue

            series = commodities[commodity]
            if (series["code"], slug, date) in already:
                counts["already"] += 1
                continue

            kept.append(
                {
                    "item": commodity,
                    "price": row["price"],
                    "location": slug,
                    "unit": series["unit"],
                    "quantity": str(series["quantity"]),
                    "date": date,
                    "currency": row.get("currency") or currency,
                    "external_id": f"wfp-hdx:{row.get('commodity_id', '')}:{row.get('market_id', '')}:{date}",
                }
            )

    kept.sort(key=lambda r: (r["item"], r["location"], r["date"]))

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8", newline="") as handle:
        # LF, not the csv module's default CRLF: the batch is keyed by the
        # file's checksum, and a file whose line endings depend on the platform
        # that wrote it is a file that imports twice.
        writer = csv.DictWriter(
            handle,
            fieldnames=["item", "price", "location", "unit", "quantity", "date", "currency", "external_id"],
            lineterminator="\n",
        )
        writer.writeheader()
        writer.writerows(kept)

    by_item: dict[str, int] = {}
    for row in kept:
        by_item[row["item"]] = by_item.get(row["item"], 0) + 1

    print(f"wrote {len(kept)} rows to {args.out}")
    for item, n in sorted(by_item.items()):
        print(f"  {item}: {n}")
    print("skipped: " + ", ".join(f"{k}={v}" for k, v in counts.items() if k != "rows"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
