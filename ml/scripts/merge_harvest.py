# SPDX-License-Identifier: Apache-2.0
"""Merge a harvest of real product wordings into the catalogue and the eval set.

**Why this is a script rather than a set of edits.** The last harvest was merged
by hand with a throwaway inserter that matched the line `variants:` exactly. Two
items were written in inline flow style — `variants: [a, b]` — so the search ran
straight past them into the *next* item, and nine egg wordings were filed under
canned tuna, four sanitary-pad wordings under cooking gas, and ten water wordings
under a ballpoint pen. A reporter writing about pads would have matched a gas
cylinder. None of it was visible in the diff without reading two thousand lines.

So this does the insertion and then **parses the file back and proves it landed
where it was meant to**. The verification is the point; the insertion is the easy
half. If verification fails nothing is written.

**Where each wording goes is decided by a hash, not by preference.**
`sha256(normalised) % 2` sends half of every labelled harvest into the catalogue,
where it becomes vocabulary the matcher can use, and half into the evaluation
set, where it stays a test. The rule is deterministic and unsalted so it can be
reproduced by anyone, and so that nobody — including whoever is trying to make
the number go up — can nudge a hard string onto the teaching side.

**Four things are refused outright:**

- a wording already in the evaluation set, which would let the matcher be scored
  on its own vocabulary
- a wording already a variant of any item, which changes nothing
- a bare single token that already names two or more different items — the
  head-noun rule. One item owning `طماطم` was once responsible for 72% of all
  matcher errors.
- a wording labelled to an item that does not exist in the country file

Distractors — real products that are not basket items — are never promoted. They
go to the evaluation set as hard negatives, which is the only place they are
worth anything.

Run from `ml/`:
    .venv/bin/python scripts/merge_harvest.py --harvest <dir> --country ly [--apply]

Without `--apply` it reports what it would do and writes nothing.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "src"))

from qeema_ml.matching.normalise import normalise  # noqa: E402

REPO = Path(__file__).resolve().parents[2]

#: Wordings held back because their correct label is an open question.
#:
#: Augmentin is co-amoxiclav — amoxicillin with clavulanic acid — and the bare
#: strengths Libyan pharmacies print say so outright: 156 is 125+31 and 457 is
#: 400+57, neither of which is a plain amoxicillin suspension. It is a different
#: product at a higher price, so pooling it into `amoxicillin_suspension_60ml`
#: would blur two drugs in the price series and teach the matcher to conflate
#: them.
#:
#: Five rows already in the evaluation set are labelled that way, which is a
#: separate problem and a larger one: more than half of what the matcher is
#: scored on for amoxicillin is a different medicine. Correcting those changes
#: the measured score, so it is left for its own change with its own before and
#: after — not folded into this harvest, where it would be indistinguishable
#: from the effect of the new vocabulary.
#:
#: What is refused here is only *adding more*.
HELD_BACK = {
    "augmentin": "co-amoxiclav, not amoxicillin — see the note above",
}


# ---------------------------------------------------------------- helpers ---


def teaches(normalised: str) -> bool:
    """Half of every harvest teaches; the other half tests.

    Unsalted sha256 so the split is reproducible from the string alone, and so a
    string cannot be moved to the flattering side by re-running anything.
    """
    return int(hashlib.sha256(normalised.encode("utf-8")).hexdigest(), 16) % 2 == 0


def yaml_scalar(text: str) -> str:
    """Render a string as a YAML scalar, quoting only when it must be quoted.

    JSON's double-quoted form is a valid YAML double-quoted scalar, so it is a
    safe fallback for anything with structural characters in it.
    """
    if not text or text != text.strip():
        return json.dumps(text, ensure_ascii=False)
    if text[0] in "-?:,[]{}#&*!|>'\"%@`":
        return json.dumps(text, ensure_ascii=False)
    if ": " in text or " #" in text:
        return json.dumps(text, ensure_ascii=False)
    return text


def load_country(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def variant_map(config: dict[str, Any]) -> dict[str, list[str]]:
    """item code -> its variants, exactly as the file holds them."""
    return {i["code"]: list(i.get("variants") or []) for i in config["canonical_items"]}


def token_owners(variants: dict[str, list[str]]) -> dict[str, set[str]]:
    """Which items claim each single token, for the head-noun rule."""
    owners: dict[str, set[str]] = defaultdict(set)
    for code, vs in variants.items():
        for v in vs:
            for token in normalise(v).split():
                owners[token].add(code)
    return owners


def existing_eval_strings() -> set[str]:
    """Every normalised string the matcher is currently scored against."""
    out: set[str] = set()
    for path in sorted((REPO / "ml" / "data" / "real-text").glob("*.json")):
        for row in json.loads(path.read_text(encoding="utf-8")):
            out.add(normalise(row["text"]))
    return out


# ------------------------------------------------------------ the merge -----


def insert_variants(text: str, code: str, additions: list[str]) -> str:
    """Append variants to one item's block list, anchored on its `code:` line.

    Anchored on `- code: <x>` and bounded by the next `- code:` at the same
    indent, so it is structurally impossible to run past the item and write into
    the following one — which is exactly what happened last time.
    """
    lines = text.splitlines(keepends=True)

    start = None
    for i, line in enumerate(lines):
        if re.match(rf"^  - code:\s+{re.escape(code)}\s*(#.*)?$", line):
            start = i
            break
    if start is None:
        raise KeyError(f"no item with code {code!r}")

    end = len(lines)
    for i in range(start + 1, len(lines)):
        if re.match(r"^  - code:\s", lines[i]):
            end = i
            break

    v_at = None
    for i in range(start, end):
        if re.match(r"^    variants:\s*$", lines[i]):
            v_at = i
            break
    if v_at is None:
        raise KeyError(f"item {code!r} has no block-style `variants:` key")

    # Walk to the last real entry of the list.
    #
    # A comment never ends a YAML block list, at any indentation. `cooking_gas_11kg`
    # carries a six-line comment indented to 2 — shallower than the list itself —
    # with twenty-eight more variants below it, and treating that as the end put
    # new wordings in the middle of the list instead of at the end. Counts still
    # matched and nothing crossed an item boundary, so only comparing the parsed
    # result against the intended one caught it.
    #
    # Tracking the last `- ` entry rather than the last indented line means
    # trailing comments stay below the insertion, where they belong to whatever
    # follows them.
    insert_at = v_at + 1
    for i in range(v_at + 1, end):
        stripped = lines[i].strip()
        if not stripped or stripped.startswith("#"):
            continue
        indent = len(lines[i]) - len(lines[i].lstrip())
        if indent <= 4:
            break
        if stripped.startswith("- "):
            insert_at = i + 1

    block = [f"      - {yaml_scalar(v)}\n" for v in additions]
    return "".join(lines[:insert_at] + block + lines[insert_at:])


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--harvest", required=True, type=Path)
    ap.add_argument("--country", default="ly")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--out-name", default=None)
    args = ap.parse_args()

    country_path = REPO / "countries" / f"{args.country}.yaml"
    config = load_country(country_path)
    before = variant_map(config)
    owners = token_owners(before)
    known = {normalise(v) for vs in before.values() for v in vs}
    in_eval = existing_eval_strings()

    files = sorted(args.harvest.glob("*.json"))
    if not files:
        print(f"no harvest files in {args.harvest}")
        return 1

    teach: dict[str, list[str]] = defaultdict(list)
    test_rows: list[dict[str, Any]] = []
    refused: dict[str, list[str]] = defaultdict(list)
    seen: set[str] = set()
    prices: list[dict[str, Any]] = []
    dialect: list[dict[str, Any]] = []
    extras: dict[str, list[Any]] = defaultdict(list)

    for path in files:
        blob = json.loads(path.read_text(encoding="utf-8"))
        prices.extend(blob.get("prices") or [])
        dialect.extend(blob.get("dialect") or [])
        for key in ("disambiguation", "notebook_question", "pack_words"):
            extras[key].extend(blob.get(key) or [])

        for row in blob.get("wordings") or []:
            text = (row.get("text") or "").strip()
            if not text:
                continue
            norm = normalise(text)
            if not norm:
                refused["empty after normalisation"].append(text)
                continue
            if norm in seen:
                continue
            seen.add(norm)

            if norm in in_eval:
                refused["already an evaluation string"].append(text)
                continue

            held = next((r for k, r in HELD_BACK.items() if k in norm.lower()), None)
            if held is not None:
                refused[f"held back: {held}"].append(text)
                continue

            code = row.get("expected")
            if code in (None, "", "null"):
                test_rows.append({"text": text, "expected": None, "source": row.get("source", "")})
                continue
            if code not in before:
                refused[f"unknown item code {code!r}"].append(text)
                continue
            if norm in known:
                refused["already a catalogue variant"].append(text)
                continue

            tokens = norm.split()
            if len(tokens) == 1 and len(owners.get(tokens[0], set()) - {code}) >= 1:
                also = sorted(owners[tokens[0]] - {code})
                refused[f"head-noun: bare token also names {', '.join(also)}"].append(text)
                continue

            if teaches(norm):
                teach[code].append(text)
                known.add(norm)
            else:
                test_rows.append({"text": text, "expected": code, "source": row.get("source", "")})

    # ------------------------------------------------------------ report ----
    print(f"harvest files      : {len(files)}")
    print(f"distinct wordings  : {len(seen)}")
    print(f"-> teach catalogue : {sum(len(v) for v in teach.values())} across {len(teach)} items")
    print(f"-> test set        : {len(test_rows)}"
          f" ({sum(1 for r in test_rows if r['expected'])} labelled,"
          f" {sum(1 for r in test_rows if not r['expected'])} distractors)")
    print(f"prices reported    : {len(prices)}")
    print(f"dialect terms      : {len(dialect)}")
    for key, rows in extras.items():
        if rows:
            print(f"{key:19s}: {len(rows)}")

    if refused:
        print("\nrefused:")
        for reason, items in sorted(refused.items(), key=lambda kv: -len(kv[1])):
            print(f"  {len(items):4d}  {reason}")
            for ex in items[:3]:
                print(f"          e.g. {ex}")

    if teach:
        print("\nwould add to the catalogue:")
        for code in sorted(teach, key=lambda c: -len(teach[c])):
            print(f"  {len(teach[code]):4d}  {code:28s} ({len(before[code])} -> "
                  f"{len(before[code]) + len(teach[code])})")

    if not args.apply:
        print("\n(dry run — nothing written; pass --apply to write)")
        return 0

    # ------------------------------------------------------------- write ----
    text = country_path.read_text(encoding="utf-8")
    for code, additions in teach.items():
        text = insert_variants(text, code, additions)

    # Prove it landed where it was meant to *before* touching the real file.
    reparsed = variant_map(yaml.safe_load(text))
    problems = []
    for code, vs in reparsed.items():
        expected = before[code] + teach.get(code, [])
        if vs != expected:
            problems.append(
                f"  {code}: expected {len(expected)} variants, file now has {len(vs)}"
            )
    if len(reparsed) != len(before):
        problems.append(f"  item count changed: {len(before)} -> {len(reparsed)}")
    if problems:
        print("\nVERIFICATION FAILED — nothing written:")
        print("\n".join(problems))
        return 1

    country_path.write_text(text, encoding="utf-8")
    print(f"\nwrote {country_path.relative_to(REPO)} (verified: every wording under its own item)")

    if test_rows:
        name = args.out_name or f"harvest_{args.country}_2026-08-21.json"
        out = REPO / "ml" / "data" / "real-text" / name
        out.write_text(
            json.dumps(test_rows, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        print(f"wrote {out.relative_to(REPO)} ({len(test_rows)} rows)")

    # Deliberately NOT under real-text/: the evaluator globs that directory and
    # expects every file in it to be a list of rows. A findings file sitting
    # beside them is a different shape and breaks the run.
    side_dir = REPO / "ml" / "data" / "harvest-findings"
    side_dir.mkdir(parents=True, exist_ok=True)
    side = side_dir / f"{args.country}_{args.out_name or 'findings'}.json"
    side.write_text(
        json.dumps(
            {"prices": prices, "dialect": dialect, **{k: v for k, v in extras.items() if v}},
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"wrote {side.relative_to(REPO)} (prices and dialect, for review by hand)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
