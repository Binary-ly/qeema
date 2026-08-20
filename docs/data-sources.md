<!-- SPDX-License-Identifier: Apache-2.0 -->

# Real data sources — a verified inventory

Every source below was **fetched**, not merely found. Each entry states the HTTP
status observed, the licence as published, and what the data actually contains
rather than what its metadata advertises. Where a source's own labelling is
wrong, that is recorded, because the wrong ones are the dangerous ones.

Verified 18 August 2026. Row counts and date ranges will have moved since.

> **Why this file exists.** Every matching figure this platform reports is
> measured against a corpus a language model wrote, and its own source file
> says so. This is the inventory of data that a person or an institution
> actually collected, which is the only kind that can turn a prototype into a
> pilot.

---

## The one to start with

### WFP — Libya Food Prices  ✅ primary source

| | |
|---|---|
| HDX slug | `wfp-food-prices-for-libya` |
| Download | `https://data.humdata.org/dataset/dc632a15-d376-496f-976f-c3282e67bc28/resource/49e3a2ae-629e-46f3-8529-7e1fd0277b24/download/wfp_food_prices_lby.csv` |
| Status observed | `200`, 7,760,599 bytes |
| Licence | Creative Commons Attribution for Intergovernmental Organisations (**CC BY-IGO 3.0**) |
| Rows | **67,644** |
| Coverage | 2017-06-15 → 2026-05-15, **108 months**, 39 markets (21 currently reporting), 36 commodities |
| Cadence | monthly; dataset last modified 2026-08-16 |

**Every row is `priceflag: actual` and `pricetype: Retail`.** Not one estimated
value in nine years. That is why this is the primary source and not the
alternative below.

Columns: `date, admin1, admin2, market, market_id, latitude, longitude,
category, commodity, commodity_id, unit, priceflag, pricetype, currency, price,
usdprice`.

**It contains the parallel exchange rate.** A commodity row named
`Exchange rate (unofficial)`, unit `USD/LCU`, Tripoli centre, **104 monthly
observations 2017-06 → 2026-02**. This is easy to miss because it is filed as a
commodity rather than published as an FX dataset — a dataset-level search for
Libyan exchange rates does not surface it.

Its identity was confirmed against WFP VAM's own *"Libya Black Market Exchange
Rate"* page, measured independently: June 2019 reads **4.40 in both**, and
November 2018 and November 2019 agree within the difference you would expect
between a daily series and a monthly mid-point.

---

## The finding that matters most

**WFP's own `usdprice` column is struck at the official rate, and the same file
carries the parallel rate that contradicts it.**

Nothing documents this. It was derived by taking the median of
`price ÷ usdprice` per month, which recovers the conversion rate WFP used:

| Date | Implied rate in `usdprice` | `Exchange rate (unofficial)` | Overstatement |
|---|---|---|---|
| 2017-06 | 1.389 | 8.17 | **5.88×** |
| 2019-06 | 1.389 | 4.40 | 3.17× |
| 2020-12 | 1.339 | 5.78 | 4.32× |
| 2021-06 | 4.464 | 5.05 | 1.13× |
| 2025-06 | 5.434 | 7.71 | 1.42× |
| 2026-02 | 6.303 | 9.61 | **1.52×** |

The implied rates track Libya's real monetary history exactly — the ~1.40 peg,
the January 2021 devaluation to ~4.48, the drift to 6.30 — which is what
establishes that the column is the official rate rather than an artefact.

This is the platform's founding argument, demonstrated on the humanitarian
sector's own gold-standard dataset: **a USD figure published by a UN agency
overstated Libyan purchasing power by 488% in 2017 and by 52% in early 2026.**
Both numbers live in the same file.

---

## What it can and cannot price

Measured against `countries/ly.yaml` (27 basket items):

**17 of 27 items (63%)** are priceable from real WFP data, across **15 of the
16 configured Libyan locations**: wheat flour, rice, pasta, couscous, sugar,
cooking oil, tomato paste, tomatoes, eggs (tray of 30), chicken, sanitary pads,
cooking gas (11kg), infant formula, canned tuna, milk, drinking water, soap.

Four need care, and must not be imported as exact matches:

| Basket item | WFP series | Difference |
|---|---|---|
| `infant_formula_400g` | Milk (powder, infant formula), **KG** | priced per kilo, not per 400g tin |
| `drinking_water_20l` | Water (drinking), **L** | priced per litre, not per 20L container |
| `canned_tuna_185g` | Fish (tuna, canned), **200 G** | 200g vs 185g |
| `uht_milk_1l` | Milk (pasteurized), L | pasteurised is not UHT |

**The 10 it cannot price are the reason this platform exists:**

`ors_sachet` · `paracetamol_suspension_60ml` · `amoxicillin_suspension_60ml` ·
`school_notebook_80p` · `ballpoint_pen` · `school_backpack` ·
`baby_cereal_400g` · `olive_oil_1l` · `harissa_can` · `bakery_flour_50kg`

Paediatric medicines, school materials, baby cereal, and three Libyan staples.
The sector's best price dataset tracks the general food basket and **none of the
medicines or school supplies a child needs**. That gap is the crowdsourced
layer's job.

Conversely WFP tracks 18 commodities the basket does not, and at least one —
**Diapers (30 pcs)** — arguably belongs in a child-weighted basket and is worth
adding.

---

## Sources that look better than they are

### World Bank — Libya Real Time Prices ⚠️ partly modelled

Slug `libya-real-time-prices`. Licence **plain CC BY** — cleaner than WFP's
IGO 3.0 — fortnightly, current to 2026-08-01, 42 markets. Superficially the
better source, and it is not, for two reasons.

**It is partly machine-generated.** The World Bank's own dataset notes, verbatim:
*"compiled and updated weekly by the World Bank Development Economics Data Group
(DECDG) using a combination of direct price measurement and **Machine Learning
estimation of missing price data**"*, based on *"price information gathered from
the World Food Program (WFP), UN-Food and Agricultural Organization (FAO)"*. So
it is derived from WFP rather than independent of it, and an unknown share of it
is estimated. A platform whose loudest commitment is that estimates are never
disguised as observations cannot import that as observed. It is a cross-check,
and any value taken from it must carry `is_imputed`.

**Its currency file is almost empty.** Of 16 non-derived columns across 4,830
rows: `batteries`, `candles`, `charcoal`, `firewood`, `soap`,
`milling_cost_sorghum` and all three `wage_*` columns are **100% empty**. Only
`exchange_rate_unofficial` is populated — with **48 distinct values across 115
dates and 42 markets**, i.e. one national number copied per market.

**And that column is mislabelled.** The World Bank's ticker metadata calls it
*"Unofficial exchange rate (Parallel-market Estimate)"*. It reads 1.43 in 2017
and 1.34 in December 2020, jumping to 4.46 in January 2021 — the CBL peg and the
CBL devaluation. The street rate in that period was 6–9. **It is the official
rate under a parallel label**, and importing it trusting the column name would
have silently destroyed the platform's central claim.

### HXL tooling — mostly retired

OCHA retired `hxlstandard.org`, the HXL Proxy and HDX Quick Charts on
**31 January 2026**; both domains now 301 to a decommissioning notice. HDX
states it *"will no longer be asking data contributors to add HXL tags"*. The
standard itself was explicitly **not** retired and `libhxl` is still published
(5.2.2). Of every HDX resource fetched for this inventory, only three carried a
hashtag row — and the WFP price CSVs are not among them.

---

## Other verified sources

| Source | Status | Licence | Use |
|---|---|---|---|
| **HDX HAPI** `hapi.humdata.org/api/v2/...` | `200`, keyless | per-resource, CC BY-IGO upstream | Query API over the same WFP data, **both countries**, with P-codes the CSVs lack. The `app_identifier` is self-minted base64 of `app:email`, not an issued key. |
| **COD-AB Libya / Venezuela** | `200` | CC BY-IGO 3.0 | Authoritative admin boundaries: adm1/adm2 P-codes, **English + Arabic names**, centroids. The join key everything else needs. |
| **Libya population by mantika** | `200` | CC BY | Only admin-level Libyan population available — adm3, sex × age **including under-18**. HXL-tagged. 2021, and there is no COD-PS for Libya at all. |
| **WFP — Venezuela Food Prices** | `200`, 19,922 rows | CC BY-IGO 3.0 | 2022-08 → **2025-05, stalled 15 months**. 9 of 23 states. **100% USD-denominated**, so it cannot show the FX story by itself. |
| **BCV** (Venezuela central bank) | `200` | **none published** | Official reference FX, daily, plus national CPI by COICOP group to July 2026. Cite-only — no licence grant exists. |
| **ve.dolarapi.com** | `200`, keyless | **none published** | Official **and parallel** VES rates. Verified 18 Aug 2026: parallel **877.90** vs official **773.31** = **+13.5%**. Its official figure matched BCV's own homepage exactly. Spot only — no history, so a deployment must store its own series from day one. |
| **World Bank WDI API** | `200`, keyless | **CC BY 4.0** | National annual CPI, both countries. Contextual. |
| **FAO FPMA** | `200`, keyless | unstated | Libya only, market-level — but `source_name: "Various via WFP VAM"`, so it is republished WFP data, not independent. |
| **INFORM Severity (ACAPS)** | `200` | CC BY | Monthly 1–10 crisis severity, the only source scoring both countries on one scale. Parse with `data_only=True`; the live sheet is formulas. |

---

## Libyan national sources

### nashrah.ly — the Ministry's daily bulletin  ⭐ closes three gaps

| | |
|---|---|
| URL | `https://nashrah.ly/` |
| Status observed | `200`, 442,782 bytes, data **server-rendered** |
| Publisher | Libya Trade Network (شبكة ليبيا للتجارة), an agency of the Ministry of Economy & Trade |
| robots.txt | `User-agent: *` / `Disallow:` — **explicitly unrestricted**, unlike HDX |
| Licence | **none stated** — no rights, terms or copyright wording anywhere on the page |
| Cadence | **daily**; page stamped 2026-08-18 when fetched |

Columns are `إسم المنتج` (product), `وحدة القياس` (unit), `سعر القطاعي`
(retail price) and a daily change figure. Verbatim rows:

```
دقيق المخابز الصفوة    | 25 كيلو جرام | 60 دل
أرز الحبة القصيرة الصحى | 1 كيلو جرام  | 6.5 دل
أرز الحبة الطويلة سيلا  | 1 كيلو جرام  | 16 دل
```

**It prices three of the ten items WFP cannot**: `هريسة ( علبة )` 380g,
`زيت زيتون محلي` and `زيت زيتون مستورد` per litre, and `دقيق المخابز` in 25kg
sacks. It also carries ~166 products against WFP's 36, daily rather than
monthly, **in Arabic**.

Two limits before anyone builds on it. There is **no licence**, so republishing
its prices under CC-BY-4.0 would assert a right nobody granted — the safe use is
as vocabulary and as a matcher evaluation set, which is a different and far
weaker claim than redistribution. And **no geographic dimension is visible**, so
it is unclear whether these are national or Tripoli figures; Qeema's index is
per-location and cannot use a price whose market is unknown.

Its real value may be the Arabic itself. It writes tea as **شاهي** not شاي, tuna
as **تن** and **تونة** in different editions, and pasta as **مكرونة** with the
shape name **سبيقا/سبيقة/سبيقى**. It gives units a generated corpus would not
invent: `طبق (30 بيضة)` for eggs, `ربطة` for herbs, `قنطار` for bulk flour,
`دستة` for a twelve-pack.

### ltnet.gov.ly — the Ministry's own daily field survey  ⭐ the best Libyan source found

`https://ltnet.gov.ly` — **شبكة ليبيا للتجارة**, Libya Trade Network, run by the
Ministry of Economy and Trade. Found by following a Facebook post, which is a
comment on how discoverable Libyan public data is.

It publishes three things, and the first is the one that matters:

| Product | Cadence | Form |
|---|---|---|
| نشرة الأسعار اليومية المحلية | **daily**, numbered — issue 807 was posted this year | PDF |
| النشرة الشهرية التحليلية | monthly, per sector, with daily charts | PDF, ~30 pp |
| تقرير أسعار السلع الأساسية | annual | PDF |

The monthly bulletin states its own method: *"من واقع ميداني تم خلاله جمع ورصد
بيانات أسعار السلع"* — a field survey, charted daily, read weekly, compared
against the prior month as a base. Five sectors: food, vegetables and fruit,
meat/poultry/eggs, grains and feed, building materials.

**It is text, not pictures.** The April 2026 bulletin extracts cleanly with
`pypdf` — presentation-form Arabic that normalises with NFKC. That puts it in a
different class from Almasar's image cards: this one an importer could read
without a human in the loop.

Two practical notes. Bright Data's unlocker returned an **empty body** for this
host while plain `curl` got 200 and a 28 MB PDF — the expensive path was the
one that failed. And the file named `نشرة-ابريل-2026.pdf` covers **March**;
trust the period stated inside the document, not the filename.

#### What it says, April 2026

Per the pack the bulletin itself names in each column header:

| Commodity | End-April | Our reference | |
|---|---|---|---|
| دقيق الأغراض المنزلية | 2.50–3.50 /kg | 3.88 | 11% above the top |
| أرز الحبة القصيرة | 6.50–7.00 /kg | 6.06 | 7% below the bottom |
| زيت الذرة | 12.50 / **850 ml** | 9.67 /l | **55% apart** |
| زيت عباد الشمس | 11.50 / 850 ml | — | |
| السكر | 4.00–4.50 / 900 g | — | |
| طماطم المعجون | 4.00–4.50 / 400 g | — | |
| التونة المعلبة | 5.75–12.50 / 160–185 g | — | |
| الحليب المعقم | 6.00–6.75 | — | |
| البيض | 19.00–24.00 / طبق 30 | 24.75 | just above the top |
| لحم الدجاج الوطني | 17.50 /kg | 31.00 | see below |
| لحم البقر الوطني (هبرة) | 92.00 /kg | — | |
| لحم الإبل (قعود / حوار) | 67 / 85 /kg | — | |
| دقيق المخابز | 240–280 / قنطار | — | |

**Chicken is not a discrepancy, it is a story.** The bulletin has 17.50 at end
April; August reporting has it at 20 in Benghazi and 30 in Tripoli, and Almasar
recorded the jump in the first week of August. Chicken nearly doubled in four
months. Our 31.00 is an August number and the bulletin is an April one, and
both are right — which is the same lesson the tomato card taught, running the
other way.

**Cooking oil was a real error, and this caught it.** WFP's `Oil (vegetable), L`
gave 9.67 a litre. The Ministry prices the bottle Libyans actually buy, 850 ml,
at 11.50–12.50 — 13.53 to 14.71 a litre. A resident of Bani Walid, filmed in
August, says *"علبة الزيت بطنعش تلتاشر دينار"*: the tin at twelve or thirteen,
which is the bottle and matches the Ministry. Two Libyan sources against one
international series, so `cooking_oil_1l` moved to 14.12 and is now marked
`contested` with all three citations. The suspicion is that WFP's per-litre
normalisation treats an 850 ml bottle as a litre, which would account for most
of the 15%; that is a suspicion and is recorded as one.

**Its limits.** No licence is published, so cite but do not redistribute. No
per-city breakdown in the monthly bulletin — the daily one may carry it.
The `/ar/local-prices/` path is empty; the bulletins are linked from the home
page as dated upload URLs, so an importer has to scrape the index rather than
hit a stable endpoint.

### Bureau of Statistics and Census — `bsc.ly`

`200`. The national statistics office, with a Price Statistics and Index
Section. Publishes a **monthly CPI report, bilingual Arabic/English, PDF only** —
June 2026 issued within about a month, base year 2024, Food and Beverages
**40.39%** of the basket. No CSV, no API. Generic contact `info@bsc.ly`.

Two things make this the most interesting Libyan institution here. Its CPI being
PDF-only is a real, checkable gap rather than an invented pitch. And it has a
**dated, current working relationship with UNICEF Libya** — its own news item of
6 August 2026 announces the expanded national MICS7 report issued in cooperation
with UNICEF.

---

---

## Ruled out, and why

| Source | Reason |
|---|---|
| **Numbeo** | Terms forbid redistribution **and** scraping, verbatim. Disqualified entirely — not merely for runtime. Do not cite it. |
| **IMF** | `LICENSE="© International Monetary Fund Copyright. All Rights Reserved."` embedded in the SDMX payload itself. |
| **REACH / IMPACT JMMI Libya** | The best non-food series that ever existed for Libya — municipality-level, with an explicit Minimum Expenditure Basket — but **discontinued April 2023**, and IMPACT's terms are all-rights-reserved. Would need written permission. |
| **WFP Libya Market Price Monitoring** | The live successor to JMMI, monthly to May 2026, mantika-level, and it **does** carry non-food (sanitary pads LYD 5.61 national, May 2026; cooking gas LYD 10.35). **PDF only**, no licence statement, no structured counterpart on HDX. |
| **Cendas-FVM** (Venezuela) | The canasta figure every news outlet quotes. Both domains fail **DNS resolution**. There is no primary source to cite. |
| **Observatorio Venezolano de Finanzas** | The real site's post feed is serving injected gambling spam with no legitimate economics content since June 2025. A lookalike domain carries prose only. Citing either would be a liability. |
| **WFP VAM / DataViz API** | `403`/`401`; the legacy gateway is decommissioned and the replacement is behind a sign-in. Use the HDX dumps. |
| **UN Comtrade** | Keyless, but neither country reports, and it is trade data rather than consumer prices. |
| **Open Food Facts** | Real Spanish/Venezuelan product names, but **ODbL share-alike** — a derived database must stay ODbL and cannot be relicensed CC-BY-4.0. |

---

## Ingesting this, honestly

**Not with the scheduled scraper.** HDX's `robots.txt` disallows `/*.csv$` and
`/api/`, so `OpenDataCsvScraper` refuses these URLs — correctly, and it now
actually does so (its wildcard handling was fixed after this inventory found the
gap). robots.txt governs *automated crawling*; the CC BY-IGO licence governs
*reuse*. Both are respected by a deliberate, attributed, operator-initiated
import through `PartnerFileImporter`, which is what these files are published
for.

`ColumnMapping::guess()` already maps every WFP column without configuration —
`commodity`, `price`, `market`, `unit`, `date` and `currency` are all existing
aliases. What needs preparing is the vocabulary: WFP market spellings
(`Tripoli center`, `Ejdabia`, `Albayda`, `Azzawya`, `Sebha`, `Sirt`, `Alkhums`)
differ from the configured location names, and six WFP markets — Algatroun,
Aljufra, Alkufra, Nalut, Wadi Alshati, Zwara — have no location entry at all.

**Licensing the output.** CC BY-IGO 3.0 is attribution-only, but it is not CC
BY 4.0 and carries no upgrade clause. Keep each source's own attribution on the
data it contributed and licence only the derived index CC-BY-4.0 — which is what
`LICENSE-DATA` already says about third-party inputs.

---

## What nobody publishes

- **No Minimum Expenditure Basket for either country as open data.** Libya's
  exists but sits inside an all-rights-reserved spreadsheet from a programme
  that ended in 2023.
- **No open, machine-readable, historical parallel-rate series for Venezuela.**
  Only a keyless spot API with no archive.
- **No paediatric medicine or school-supply prices anywhere, for either
  country.** One REACH rapid assessment from November 2023 priced infant formula
  once. That is the whole of it.

The last of those is the gap this platform was built for, and it is now measured
rather than asserted.


---

## اقتصاد المسار — the closest thing Libya has to a daily price monitor

Found 20 August 2026 while chasing a single wrong water price, and the most
useful Libyan source in this document.

**What it is.** Almasar Economy runs its own **field surveys** — رصد ميداني —
of retail prices in Tripoli and publishes them dated, with day-on-day
percentage changes. Not a ministry, not an aid agency: a Libyan economics desk
walking into shops. Republished as dated articles by `almashhadlibya.com` and
others, which is the practical way to read it.

**What it covers, from headlines verified in one search:**

| Series | Example |
|---|---|
| Chicken and eggs | *"قائمة أسعار الدجاج والبيض في طرابلس اليوم الثلاثاء 5 مايو 2026"* |
| Cement | daily, with day-on-day change — *"قنطار أسمنت الاتحاد … 71 ديناراً مقابل 69"* |
| General retail | *"أسعار التجزئة 5 أغسطس 2026 في طرابلس"* |
| School supplies | 17 Aug 2026, itemised by sheet count and quality |
| Bottled water | 18 Aug 2026, by pack format |
| Fuel | *"سعر لتر الديزل في السوق الموازية بطرابلس يقفز 23 ضعف السعر الرسمي"* |
| Bread | *"لماذا ارتفع سعر الفردة في بعض مخابز العاصمة"* |

**Why it matters more than its size suggests.** It prices exactly what WFP and
the Ministry bulletin do not — bottled water, cooking fuel, school materials,
hygiene — which is most of the gap this platform exists to fill. It is dated,
attributable and recurring, so a price taken from it can be checked and can be
re-taken next month.

### Its Telegram channel is the machine-readable way in

`https://t.me/s/almasartvlibya` — the `/s/` preview path — **fetches cleanly**,
where the app link does not. 2.87K subscribers, and the price posts are tagged
`#اقتصاد_المسار` so they can be filtered from general news. On 20 August 2026
alone it carried red meat at 09:28, fish at 09:34 and vegetables at 09:59.

**The catch, and it is the whole engineering problem.** The prices are rendered
into a **graphic**. The caption gives the date and the category — *"أسعار
الخضروات ليوم الخميس 20 أغسطس في محال البيع بالتجزئة"* — and nothing else. A
text scrape gets you a dated index of price cards and not one number.

That is not a reason to dismiss it. A daily, dated, categorised feed of price
cards from a named market is exactly what `PartnerFileImporter` exists for; it
needs a person or an OCR pass between the channel and the importer. The 20
August vegetable card, transcribed by hand from the image, reads:

| Item | LYD/kg | | Item | LYD/kg |
|---|---|---|---|---|
| شبت / كزبرة / معدنوس | 0.25 | | طماطم "شمسي" درجة أولى | 3.00 |
| بصل أخضر "الربطة" | 2.00 | | خيار | 4.00 |
| دلاع وطني | 2.00 | | سلاطة خضراء | 4.50 |
| بصل "يابس" | 2.50 | | بطاطا | 5.00 |
| قلعاوي وطني | 2.50 | | باذنجان | 5.00 |
| كوسة | 6.00 | | فلفل أخضر حار | 6.00 |
| تين شوكي / هندي | 7.00 | | عنب مصري | 12.00 |

Footer: *"المصدر: رصد ميداني لاقتصاد المسار في سوق الحي الإسلامي للخضروات
والفواكه بطرابلس"* — a named market, not a national average.

### What that card corrected

`tomatoes_1kg` had just been set to **6.75** from WFP's March–May median. The
card puts it at **3.00** on 20 August. Both are right: tomatoes are in season.

An authoritative source, correctly read, was wrong for today by 2.25× — and
nothing in the pipeline would have caught it, because a stale number looks
exactly like a fresh one. `as_of` is not decoration, and produce is where it
bites hardest.

**Its limits, stated.** Tripoli only. No licence is published, so it can be
cited but not redistributed. Prices are images, so ingestion is not automatic. And it is a media organisation rather than a
statistical agency: the figures are a field survey, not a sampling frame, and
the articles say so themselves — *"الأسعار قابلة للتغير بحسب المحل والعروض"*.

### Two commodities with an official price and a real one

Worth recording separately, because it is the platform's whole argument turning
up in goods rather than in currency.

**Cooking gas.** Brega, the state oil marketing company, prices an 11kg refill
at **1.50 LYD**. Cylinders sell on the street at around **60**. A fortyfold gap,
and which price a household pays depends on access to a rationed booking system
whose reach nobody publishes. (The 240 LYD figure widely quoted is a *refundable
deposit* on the cylinder, not a price — the source says so explicitly.)

**Diesel.** Almasar reports the parallel-market litre in Tripoli at **23 times
the official price**.

An index that prices either at the official rate reports a country that does not
exist. That is the same failure as converting a basket at an official exchange
rate, and it is why `cooking_gas_11kg` carries `confidence: contested` in
`countries/ly.yaml` rather than a confident number.

---

## Who actually collects Libyan price data now

Verified August 2026. Stated because two widely-assumed answers are wrong.

- **The Libya Cash and Markets Working Group is archived.** Its ReliefWeb page
  carries the banner *"This operation has been archived and is no longer being
  updated"* — as does the entire Libya humanitarian operation page. "We will
  plug into the CMWG" is not available.
- **REACH/IMPACT has published nothing on Libya since June 2024.** The JMMI,
  which most people assume still runs Libyan market monitoring, ended in April
  2023; WFP states it assumed responsibility afterwards.
- **Mercy Corps has no current Libya programme** — it is absent from its own
  country list. Do not name it.
- **There is no Libyan government open-data portal.** `data.gov.ly` and
  `opendata.gov.ly` do not resolve. HDX is the de facto portal, with 229 Libya
  datasets.

So **WFP is the field**, and its data is already openly licensed — meaning a
pilot can begin with no partnership dependency and no permission request. That
is a genuine strength rather than a hedge, and it is worth stating plainly in
any application.

**No partnership, advisor or agreement exists with any organisation named in
this document.** Everything above is a verified fact about who publishes what.
None of it is a relationship. For an application question asking about partners
and advisors, the honest answer today is NA.

One verified alignment is worth knowing without overclaiming it: the Bureau of
Statistics announced on 6 August 2026 an expanded national MICS7 report issued
**in cooperation with UNICEF**. UNICEF Libya's existing statistical counterpart
is therefore the same institution that owns the CPI. That is a checkable path,
not a connection anyone has made.
