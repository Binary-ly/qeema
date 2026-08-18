<!-- SPDX-License-Identifier: Apache-2.0 -->

# Do No Harm

`SECURITY.md` asks what an attacker can do to this platform. This document asks
the harder question: **what can this platform do to people while working exactly
as designed?**

The two have different answers, and the second one is not fixed by writing
better code. A price index that is accurate, well-tested, honest about its
uncertainty and running perfectly can still put a reporter at risk, mislead an
agency into underfunding a transfer, or hand somebody a targeting list. Those
are properties of what the system publishes and to whom, not of whether it has
bugs.

Nothing here is theoretical framing. Every mitigation named below is a thing in
the repository that can be read, and every residual risk is stated because it is
still there.

---

## Who can be harmed

| | |
|---|---|
| **Reporters** | People who submit prices. In a crisis economy they are the most exposed party by a wide margin, and the least able to assess the exposure. |
| **Merchants** | Named indirectly. A published price is a statement about somebody's shop. |
| **Beneficiaries** | People whose cash transfer is sized using this index. They never touch the system and carry the consequences of it being wrong. |
| **Operators** | Whoever runs a deployment. Publishing certain numbers is not politically neutral everywhere. |

---

## 1. A reporter is identified by their own reporting

**The harm.** Reporting parallel-market prices is not a neutral act. The
parallel rate is contested in most of the economies this platform is for, and
publishing it has at times been characterised as speculation or economic
sabotage. Somebody identified as a regular contributor may face pressure from a
merchant whose prices they published, from an authority that objects to the rate
being published at all, or from an armed group in a place where those are the
authority.

**Why anonymity is not the whole answer.** The platform never asks for a name,
a phone number or an email. That is necessary and it is nowhere near
sufficient, because a reporter can be identified by the *shape* of what they
report rather than by any field:

- A town with one active reporter publishes a figure every day. That figure is
  a public statement that somebody in that town is reporting.
- Repeated daily, the pattern of *which* products are checked and *when* is a
  behavioural fingerprint, tied to a named place.
- Anyone locally who knows who walks the market with a phone each morning can
  close the gap themselves. No database is needed.

**What is implemented.**

- No identifier is ever collected: reporters are an anonymous device UUID the
  app generates, with an optional display name. No account, no phone number, no
  email address, no GPS.
- **No IP address is stored against a submission or a reporter.** There is no
  column for one. IPs are used transiently as rate-limit keys and are not
  persisted. (Laravel's optional database session driver has an `ip_address`
  column and would record one for *admin* sessions; the shipped configuration
  uses Redis sessions, so that table is unused. If you switch
  `SESSION_DRIVER` to `database`, you are choosing to store admin IPs.)
- `device_metadata` is an allow-list of three non-identifying fields — platform,
  app version, whether it was queued offline. Anything else the client sends is
  discarded rather than filtered, so a future client cannot widen it by
  accident. (`app/Actions/RecordSubmission.php`)
- Reporter identity, display names and raw submitted text are never exposed on
  the public API. Only aggregates are.
- Photographs have EXIF, XMP, IPTC and comment blocks stripped **before** the
  file reaches disk, and a photograph whose metadata cannot be removed is not
  stored at all. (`app/Support/Media/ImageMetadataStripper.php`)
- **Small observation counts are withheld.** `observation_count: 1` on an
  unauthenticated endpoint says one person reported that product, in that town,
  on that day. Below `QEEMA_MIN_DISCLOSED_OBSERVATIONS` (default 5) the count
  is withheld and the payload says `observation_count_disclosure: "withheld"`.
  The price, its confidence interval and the imputation flag are never
  withheld — a consumer loses precision on how well supported a number is,
  never the number.
- **A reporter can be erased.** `qeema:reporter:forget` deletes the reporter
  row, the device reference, the display name, the reputation history and every
  photograph they submitted, from disk and not only by reference — while the
  anonymous price observations and every published figure survive untouched.

**Residual risk, stated plainly.**

- Publishing a figure for a thin-coverage town still discloses that *somebody*
  reports there. Suppressing the count narrows the disclosure; it does not
  remove it. Not publishing thin-coverage places at all would remove it, and
  would also abandon exactly the places this platform exists to serve. The
  trade is deliberate and it is the operator's to re-make.
- Timing and location survive erasure. A submission still records that a price
  was reported in this town on this afternoon. Erasure removes the identifier;
  it is not a guarantee of unlinkability against an observer with independent
  knowledge of who was where.
- Raw text survives erasure by default, because a published figure has to be
  traceable to what somebody typed. Text is free-form, so a reporter who typed
  their own name into a price field leaves it behind. `--scrub-text` handles
  that case and is deliberately not the default, because it destroys the
  matcher's evidence for a resolution.
- A photograph can show a face, a shopfront or a licence plate. Stripping
  metadata does nothing about the picture. Retention and access stay an operator
  decision and photographs stay behind the admin login.

**What an operator must decide before a real pilot.** Whether the reporter pool
in each location is large enough that publishing a figure there is safe; what
the retention period for photographs is; and whether reporters have been told,
in their own language, what is published and what is not. The platform cannot
make any of those calls.

---

## 2. The index is used to target the people it measures

**The harm.** A per-town, per-item price series is also a map of who is selling
above an official price. A government enforcing price controls, or an armed
group extracting from merchants, could read it that way. The merchants are not
users of this system and never consented to being measured.

**What is implemented.** Prices are published as **aggregates at the location
level** — a town's basket cost, not a shop's price list. No merchant is named
anywhere in the schema, the API or the export. There is no shop entity to name.

**Residual risk.** In a small settlement with one shop selling a given item, the
location aggregate *is* that shop's price. Aggregation gives no protection when
the aggregate has one member. This is the same k-anonymity problem as §1 seen
from the merchant's side, and the observation-count threshold does not solve it
because the price itself is the disclosure.

**Not mitigated in code, deliberately.** Suppressing prices in thin markets
would defeat the platform's purpose. An operator working somewhere price
enforcement is violent should publish at a coarser geography. Nothing in the
code fixes what a "location" is: `countries/*.yaml` lists them, and a country
file that lists whole municipalities rather than towns produces a coarser index
with no code change. That is a configuration decision an operator makes with
local knowledge, and it is the reason locations are configuration rather than
schema.

---

## 3. A wrong number moves real money

**The harm.** If a cash transfer is sized from this index and the index is too
low, children get less than they need. That is the most consequential failure
mode here and it does not require any adversary.

**What is implemented.**

- **Imputed values are never disguised as observed ones.** `is_imputed` travels
  from the estimator through the database, the API and the UI, and it is emitted
  first and unconditionally in every item payload. A consumer that treats an
  estimate as a measurement has to ignore something impossible to miss.
- Every figure carries `coverage`, `imputed_share`, a confidence interval that
  accounts for both sampling noise *and* imputation uncertainty, and a
  `comparable` flag that is false until every basket item has a price — because
  a partially-observed basket costs less simply because part of it is missing.
- `cost_usd` is null rather than invented when no usable exchange rate exists,
  and `fx_is_stale` says when the rate is old.
- The chain-linked `index_level` is what survives a basket revision; `cost`
  steps at a revision for reasons that are not price movements, and both are
  published so a consumer can tell which they are looking at.

**Residual risk.** Every one of those qualifiers can be ignored. Nothing stops a
consumer plotting `cost_local` across a basket revision and reporting the step
as inflation. The defence is documentation and the qualifiers being
unavoidable in the payload, not enforcement — an open API cannot enforce how it
is read.

---

## 4. Somebody poisons the index on purpose

**The harm.** Moving a published figure by submitting crafted prices. If the
index sizes transfers, that is a way to move money.

**What is implemented.** Anomaly screening on every submission before it can
reach a figure (bounds, robust statistics, IsolationForest); a rejected verdict
marks the observation invalid and the index reads only valid observations;
reputation weighting via a Beta posterior in which **only human-confirmed
verdicts** update the prior, so an unlucky new reporter cannot be spiralled out
by automated flags alone; per-device submission rate limits; reporter blocking;
and immutable raw submissions, so corrections supersede and never overwrite.

**Residual risk.** A patient, distributed attacker submitting plausible prices
from many devices over a long period is not distinguishable from a genuine shift
in the market by any of the above — that is what "plausible" means. Detection at
that point is a question for whoever runs the deployment and knows the market,
not for a model.

---

## 5. Children

The platform is *about* what children need. It collects **no data about
children**, from children, or from anyone under 18 in any capacity the code can
express: there is no age field, no household composition, no beneficiary
records, no individual-level data of any kind. The child-weighting is applied to
the *basket* — which products, in what quantities — in `countries/*.yaml`, and
is derived from published nutritional and humanitarian guidance rather than from
any person.

Reporters are assumed to be adults. Nothing verifies that, and nothing could
without collecting exactly the identifying data this design refuses to collect.
An operator recruiting reporters is responsible for who they recruit.

---

## 6. Content submitted by strangers

`raw_text` is free text typed by the public. It reaches the admin review queue,
where a human sees it. It is never published.

**Residual risk.** There is no moderation layer between submission and the
review queue, so a reviewer can be shown abusive text. That is a smaller harm
than the alternatives — auto-filtering free text in Libyan dialect and Arabizi
would silently discard legitimate product wordings, which is the failure this
platform can least afford — but it is real and it is unmitigated.

---

## 7. The operator

Running a public parallel-market rate is not politically neutral. In some
jurisdictions publishing it invites regulatory attention; in others it invites
worse. This is Apache-2.0 software anybody can deploy, and nothing in it
constrains where. An operator should understand the local position before
publishing, and should not assume that self-hosting makes them anonymous.

---

## What would change this document

It is written from a codebase, not from a pilot. **No real reporter has used
this platform yet.** Once one has, the assumptions above about how reporters
behave, what they understand themselves to be disclosing, and what actually
happens in a market become testable, and several of them will be wrong. The
first pilot's job includes finding out which. See [pilot.md](pilot.md).

## Related

- [SECURITY.md](../SECURITY.md) — the adversary-facing threat model, disclosure
  process and operator responsibilities
- [privacy.md](privacy.md) — what personal data exists, why, and for how long
- [dpg-standard.md](dpg-standard.md) — this document is indicator 9 of that
  self-assessment
