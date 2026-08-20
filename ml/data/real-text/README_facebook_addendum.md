<!-- SPDX-License-Identifier: Apache-2.0 -->

# `facebook_ly_2026-08-20.json` — 519 held-out rows

251 positives, 268 distractors, collected 20 August 2026 from public Libyan
commerce pages on Facebook by five parallel agents, one per domain.

## Why this file exists

The evaluation had **29 unseen wordings**. One error on 29 is 3.45 points, so
the honest interval was [82.8%, 99.4%] — wide enough that the headline could
move four points on a single string, and it did. No number in that range was
distinguishable from any other.

1,110 wordings were harvested. After dropping duplicates, non-Libyan currency
contexts and 26 rows that collided with the existing evaluation, 1,066 remained.
Each item's *new* wordings were then split in half by a hash of the normalised
string: half became catalogue vocabulary, half came here. The hash makes the
split deterministic and stops anyone nudging it until the score improves.

Held-out positives went from 29 to 280 across all sets. The interval narrowed
from ±16 points to ±3.

## What it showed

**91.8%, not 96.6%.** On 280 unseen wordings the matcher gets 257 right, 95% CI
[88.0%, 94.5%]. The old 96.6% was not wrong, it was 28/29 — a sample too small
to distinguish 92% from 97%. The model did not get worse; the measurement got
honest.

Precision did not move: **0 of 391 distractors wrongly auto-resolved**, which is
the failure that would corrupt a published index.

## The rules it was collected under

Every agent was given the same constraints and the merge re-checked all of them:

- **Libya only.** Rows carrying جنيه, ريال, درهم, شيكل, "ألف" as a price
  magnitude, DA or د.ت were rejected. Agents reported discarding Egyptian,
  Algerian, Tunisian, Palestinian, Jordanian, Iraqi and Kuwaiti results.
- **Never an evaluation string.** Checked at harvest and again at merge.
- **Product names only**, 2–8 words. No post bodies.
- **No people.** No personal names, no phone numbers in the text, no
  `/profile.php` sources. Business pages and public buy/sell groups only. One
  exception is recorded honestly: a few sources are business pages whose vanity
  URL is the shop's own publicly advertised number. That is a business contact,
  not a person's data, and provenance needs a resolvable link.
- **Labels are ours, text is not.** Deciding `دحي وطني` means `eggs_30` is a
  judgement. Every string being real is a fact.

Two labels the harvesting agent itself flagged as arguable, and they are the
right ones to revisit first: Nido 400 g growing-up milk, labelled null rather
than `infant_formula_400g`; and artisanal olive-oil soap bars, labelled null
rather than `bath_soap_bar`.

## A defect in this test set, quantified rather than quietly fixed

Sixteen of 537 positives (3.0%) state a pack size that contradicts the item they
are labelled with: pasta at 400 g against a `pasta_500g` code, tuna at 140 g and
160 g against `canned_tuna_185g`, flour sacks of 25 kg against
`bakery_flour_50kg`, and `حليب اطفال نيدو 1800 جرام` against a 400 g formula code.

Two agents disagreed about exactly this. The negatives agent deliberately filed a
160 g tuna tin as a distractor, calling it "the sharpest size trap in the set";
the staples agent filed the same shape as a positive. Both were reasoning
honestly and the project has not decided between them.

It is a **specification** question, not a labelling slip. `canned_tuna_185g` can
mean the 185 g tin exactly, or the category of standard tinned tuna that Libyan
shops stock at 140/160/185 g side by side. The Ministry bulletin takes the second
reading — it prices `المكرونة` as one series and prints the range "(400-500)" in
its own column header. The corpus takes the first, warning that "a matcher
scoring well on those would be conflating price points a price monitor exists to
keep apart".

These rows are left exactly as they are. Relabelling them would raise the
headline by up to three points on a judgement made *after* seeing which way the
model got them wrong, which is how a test set stops being one. The number to
carry is: of the measured error, up to 3 points may be specification rather than
model failure, and the way to settle it is to decide what the item codes mean.

