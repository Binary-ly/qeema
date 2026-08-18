<!-- SPDX-License-Identifier: Apache-2.0 -->

# Real product wordings — evaluation sets

Product names **no language model wrote**, with the basket item each one means.
Used by `ml/scripts/real_text_evaluation.py`.

The distinction from `countries/corpus/*.json` is the whole point of these
files. That corpus was authored by a language model and says so; a matching
figure measured against it is partly a measurement of the generator. These
strings were collected from publishers who had never seen this catalogue.

| File | Source | Strings | Collected |
|---|---|---|---|
| `nashrah_ly_2026-08-18.json` | [nashrah.ly](https://nashrah.ly/) — daily bulletin of Libya Trade Network, an agency of the Libyan Ministry of Economy and Trade | 166 (61 basket, 105 not) | 18 Aug 2026 |
| `wfp_lby_commodities.json` | Commodity names from WFP's Libya food prices dataset on HDX (CC BY-IGO) | 35 (17 basket, 18 not) | 18 Aug 2026 |

**The labels are ours, the text is not.** Deciding that `أرز الحبة القصيرة الصحى`
means `rice_1kg` is a judgement we made and can be argued with. Every string
being real is a fact. Where a mapping was arguable — chicken liver against a
`chicken_1kg` item — the row was made a distractor rather than a generous
positive.

**The distractors matter as much as the positives.** A bulletin that prices
cement, reinforcing bar, sheep feed and thirty vegetables supplies negatives an
invented list does not contain. Resolving one of those confidently to a basket
item is the failure that corrupts a published index.

`expected: null` means "belongs to no basket item". It does not mean the product
is unimportant — several of these, diapers especially, arguably belong in a
child-weighted basket and are not in it yet.

Reproduce:

```bash
cd ml
.venv/bin/python scripts/real_text_evaluation.py \
  data/real-text/nashrah_ly_2026-08-18.json \
  data/real-text/wfp_lby_commodities.json
```
