<!-- SPDX-License-Identifier: Apache-2.0 -->

# Libyan shop wordings from public Facebook commerce pages

63 product strings — 39 labelled to a basket item, 24 to none — collected from
Libyan grocery, pharmacy and stationery pages in August 2026.

## What this is for, and what it is not

It was collected to be **training** data. It is not enough to train on, and
saying so is more useful than pretending otherwise: 39 positives across 18 items
gives the fine-tuner about 23 wordings after its 60/40 split, which trains
nothing. Fine-tuning this domain took 444 wordings to move the needle.

So it is used as a **second evaluation set**, and it earned its place on the
first run by finding two real defects that `ml/data/real-text/` could not:

- `دحي الاستيكة` resolved to **ballpoint_pen** at 0.66. دحي is the ordinary
  Libyan word for eggs, and `eggs_30` carried five catalogue variants, none of
  them Libyan — while 62 wordings including six دحي forms sat in the reporter
  corpus, which the matcher never reads.
- `زيت زيتون غرياني درجة اولى` resolved to **cooking_oil_1l**. Origin is part of
  the product name in Libyan usage, and غرياني was not in the catalogue.

Both are fixed. On the same set the score went from 91.3% to 95.5% on unseen
wordings, and `لبن دليس` still resolves to `eggs_30`, so it is not finished.

## The rules it was collected under

**Never an evaluation string.** Every candidate is normalised and checked
against `ml/data/real-text/*.json` before it is added, and again before any of
it becomes a catalogue variant. Nothing that would let the matcher be scored on
its own vocabulary gets in.

**Product names only.** Short factual designations — `سكر المعمورة`,
`مكرونة جوهرة خرز` — with the page they came from. No post bodies, no images, no
reproduction of anyone's writing beyond the name of a thing for sale.

**No people.** Business pages only. No personal names, no phone numbers, no
profile links, nothing that connects a person to a purchase. `docs/do-no-harm.md`
commits to taking the words and the prices and leaving the people; a shop
advertising a price is the case where that is easiest to honour.

**The labels are ours, the text is not.** Deciding that `دحي الاستيكة` means
`eggs_30` is a judgement and can be argued with. Every string being real is a
fact.

## Provenance and reuse

Each row carries the URL it came from. Facebook's terms govern the platform, not
the fact that a shop in Gharyan sells `سكر المعمورة`; short product designations
are not authored works and are recorded here as facts about a market. Anyone
redistributing this should form their own view.

`countries/corpus/ly.json` is the contrast: that one was written by a language
model and says so. This one was written by shopkeepers.
