<!-- SPDX-License-Identifier: Apache-2.0 -->

# Digital Public Good Standard — self-assessment

The [DPG Standard](https://digitalpublicgoods.net/standard/) is nine indicators
a project must satisfy to be recognised as a digital public good. This is
Qeema's own assessment against them, with the evidence in this repository and
an honest account of what does not yet pass.

**Status: 9 of 9 met.** Where a control depends on a decision only the operator
can make, that is said plainly rather than counted as met by implication.

This is a self-assessment. It has not been reviewed by the DPG Alliance, and
nothing here should be read as a recognition that has been granted.

---

## 1 · Relevance to Sustainable Development Goals — **met**

Qeema measures what it costs, in a specific place on a specific day, to buy the
things a child needs, priced against the exchange rate people actually transact
at rather than an official one.

| Goal | How |
|---|---|
| **SDG 1** — No poverty (1.2: poverty in all its dimensions, *including children*) | A child-weighted basket cost is a direct measure of the deprivation a household faces, at a granularity national CPI does not reach. |
| **SDG 2** — Zero hunger (2.1: access to sufficient food) | Staple grains, infant formula and cooking fuel are basket items; local affordability of them is the measure. |
| **SDG 3** — Health (3.2: end preventable deaths of children under 5) | Paediatric medicines, hygiene products and drinking water are priced. |
| **SDG 17** — Means of implementation (17.18: availability of high-quality, timely, disaggregated data) | The whole product. Data disaggregated by location and by item, published openly, unauthenticated, in an open format, under an open licence. |

The operational case: a humanitarian agency sizing a cash transfer needs to know
what a transfer is worth in *this town this week*. Official statistics are late,
national, and priced at a rate nobody can buy at. That gap is what this exists
to close.

---

## 2 · Use of approved open licenses — **met**

| | |
|---|---|
| Software | **Apache-2.0** — [`LICENSE`](../LICENSE), OSI-approved. Every first-party source file carries an `SPDX-License-Identifier` header — all 431 PHP and Python files, and every shell script, TypeScript and JavaScript file the project wrote. The only unheadered files are vendored third-party assets under `api/public/js/filament/`, which are Filament's MIT code and are not ours to relabel. |
| Data | **CC BY 4.0** — [`LICENSE-DATA`](../LICENSE-DATA). Asserted at the point of delivery too: the CSV export sends `X-Qeema-License: CC-BY-4.0`, because a file passed on loses the context the API page carried. |
| Model weights | `intfloat/multilingual-e5-base` — MIT. |
| Location geometry | Derived from OpenStreetMap, redistributed under ODbL 1.0. |

Dependencies are OSI-licensed throughout, and this is **enforced rather than
asserted**: `make licenses` regenerates the full inventory from the lockfiles
and CI runs it on every push.

The non-obvious case is documented rather than glossed. Redis relicensed in
March 2024 to RSALv2 / SSPLv1, neither OSI-approved; Redis 8 restored **AGPLv3**
as a third option, which is what this project depends on. The reasoning,
checked against Redis's own `LICENSE.txt` rather than secondary sources, is
[ADR 0002](adr/0002-redis-licensing.md).

---

## 3 · Clear ownership — **met**

Ownership is stated in [`NOTICE`](../NOTICE): *Copyright 2026 The Qeema
Contributors*, with the copyright holder named in [`LICENSE`](../LICENSE).
The repository is `github.com/Binary-ly/qeema`. Contribution terms and the
inbound=outbound licensing rule are in
[`CONTRIBUTING.md`](../CONTRIBUTING.md), and there is a
[`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md).

---

## 4 · Platform independence — **met**

There is no mandatory dependency on any closed component, and this is
constraint **C1** of the project rather than a preference:

- **No proprietary or paid service anywhere in the runtime path.** No hosted
  inference API, no commercial geocoder, no paid map tiles, no closed database.
- **Every model runs locally from open weights**, baked into the image, so the
  system works with no network egress at all after the build.
- A correct deployment has **no third-party API keys**, because there is no
  third party. The one opt-in exception — an operator's own exchange-rate
  source — names the *environment variable* holding a token in the country
  file, never a token.
- Runs on any Docker host. The stack is PostgreSQL, Redis, PHP-FPM and Python,
  all of which are self-hostable and none of which is a managed service.

Verifiable in one command: `docker compose up` on a clean machine with no
accounts of any kind.

---

## 5 · Documentation — **met**

| | |
|---|---|
| [README](../README.md) | What it is, how to run it, what it refuses to compromise on |
| [docs/deployment.md](deployment.md) | Requirements, the full configuration reference, adding a country, backup and restore, upgrades |
| [docs/operations.md](operations.md) | What runs on its own, and what to do when it stops |
| [PLAN.md](../PLAN.md) | Technical plan, schema, formulas, decision log |
| [docs/adr/](adr/) | Architecture decision records |
| [docs/model-cards/](model-cards/) | Training data, metrics and limitations per ML component |
| [docs/assessment.md](assessment.md) | **What is proven, what is measured only against a simulation, and what is not built** |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Development setup and the non-negotiable rules |
| API reference | OpenAPI 3.0 at `/docs`, generated from source and CI-checked for drift |

`docs/assessment.md` is the one worth naming twice. It exists to separate
measured claims from simulated ones, and it is maintained to be accurate rather
than flattering.

---

## 6 · Mechanism for extracting data — **met**

Non-PII data is extractable in open, machine-readable formats without
permission, an account or a key. Constraint **C6**: the data being open is the
product.

- **Public REST API**, unauthenticated for read, JSON, documented by an
  OpenAPI 3.0 spec that CI checks against the code on every push.
- **Bulk CSV export**, streamed — `/api/v1/countries/{code}/export.csv`.
- **HXL tagging** — `?hxl=1` adds the Humanitarian Exchange Language hashtag row
  beneath the header, so the file drops directly into the tooling the
  humanitarian data ecosystem already uses (HDX, the HXL Proxy, libhxl).
- **Qualifiers travel with the numbers.** Coverage, imputation share, confidence
  intervals, comparability and exchange-rate staleness are columns and fields,
  not footnotes, so an extracted file cannot be read as more certain than it is.

No personal data is exposed by any of these. See indicator 7.

---

## 7 · Adherence to privacy and applicable laws — **met**

**What is met.** Data minimisation by construction: no account, no phone, no
email, no device location, no IP stored against a submission or a reporter, and
`device_metadata` allow-listed to three non-identifying fields. No special
categories of data. Photograph metadata stripped before the file reaches disk.
No personal data leaves the deployment, because there is no third-party service
to send it to. A working erasure path — `qeema:reporter:forget` — destroys the
person and keeps the anonymous prices. Full account in
[docs/privacy.md](privacy.md).

**Retention.** `qeema:retention:enforce` runs daily on the shipped schedule and
expires photographs and dormant reporter rows on two independently configurable
windows. Both ship **disabled**, deliberately: a retention job that starts
deleting the moment an operator upgrades is a data-loss incident wearing a
privacy costume, and the right period depends on a legal basis and a set of
promises to reporters that this project does not know. The mechanism is working,
tested and scheduled; the policy is the operator's to set, and
[docs/privacy.md](privacy.md) says so in those words.

Neither window ever touches a price observation, an index snapshot or a
published figure, whatever it is set to — there is a test whose only job is to
fail if that stops being true.

**A limit worth stating: applicable law cannot be asserted centrally.** Qeema is
deployable anywhere by anyone, so the operator is the data controller and the
regime is a fact about their deployment. The project provides the controls; it
cannot provide the compliance.

---

## 8 · Adherence to standards and best practices — **met**

**Open standards used:** OpenAPI 3.0 for the API; JSON Schema for the
Laravel↔ML contract, validated by *both* sides so the test fake cannot drift
from the real service; ISO 3166 country codes, ISO 4217 currencies, ISO 8601
timestamps; HXL for humanitarian data exchange; and an
an
`SPDX-License-Identifier` header on every first-party source file (see
indicator 2).

Not yet done: there is no `CHANGELOG.md` and no tagged release, because nothing
has been released. Both belong with the first version that is.

**Engineering practice, enforced in CI rather than promised:**

- ≥80% unit test coverage on both services, as a build gate (constraint C5)
- Static analysis at Larastan level 6; `mypy` on the Python service
- Formatting gates: Pint, `ruff check` and `ruff format --check`
- A country-agnosticism check that greps the source tree for country literals,
  so nothing country-specific can land outside `countries/*.yaml` (C3)
- A secret-shaped-value scan on every push
- End-to-end Playwright tests against the composed stack, proving the whole
  loop closes with shipped defaults and nobody running a command (C2)
- A test that fails if any configurable knob is undocumented

**Accessibility and performance**, measured rather than claimed — Lighthouse,
mobile profile:

| Surface | Performance | Accessibility | Best practices | SEO |
|---|---|---|---|---|
| Dashboard | 99 | **100** | 100 | 100 |
| Reporter PWA | 100 | **100** | 100 | 100 |

**Internationalisation:** English, Arabic and Spanish, with right-to-left
support. The reporter app works offline and syncs when a connection returns,
because the places this is for do not have reliable ones.

---

## 9 · Do No Harm by design — **met**

Assessed in full in **[docs/do-no-harm.md](do-no-harm.md)**, which asks what the
platform can do to people *while working exactly as designed* — a different
question from the adversary-facing threat model in
[SECURITY.md](../SECURITY.md).

Summary of what is implemented:

- **Reporter protection.** Anonymous by construction; identity, raw text and
  photographs never published; photograph metadata stripped before disk;
  per-item observation counts below a configurable threshold withheld, because
  `observation_count: 1` on a public endpoint states that one person reported
  that product in that named town on that day; a working erasure path.
- **Data privacy and security.** Covered above and in [privacy.md](privacy.md).
- **Protection from inappropriate content.** Public output is numeric aggregates
  only. Submitted free text is never published.
- **Protection from harassment.** No user-to-user surface exists — no messaging,
  no profiles, no comments, no social graph. There is nothing to harass through.
- **Children.** No data about children is collected in any form: no age field,
  no household composition, no beneficiary records, no individual-level data.
  Child-weighting is applied to the *basket* in `countries/*.yaml`, derived from
  published guidance, never from a person.

Residual risks are stated rather than omitted — thin-coverage locations still
disclose that somebody reports there; erasure removes the identifier but not
unlinkability; a photograph can show a face regardless of its metadata; and no
real reporter has used this platform yet, so several assumptions in that
document are untested and some will prove wrong.

---

## Summary

| # | Indicator | Status |
|---|---|---|
| 1 | Relevance to SDGs | Met |
| 2 | Approved open licenses | Met |
| 3 | Clear ownership | Met |
| 4 | Platform independence | Met |
| 5 | Documentation | Met |
| 6 | Mechanism for extracting data | Met |
| 7 | Privacy and applicable laws | Met |
| 8 | Standards and best practices | Met |
| 9 | Do No Harm by design | Met |
