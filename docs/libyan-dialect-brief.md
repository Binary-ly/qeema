# Libyan Arabic — research brief for corpus verification

Compiled from web research. Everything here is a *lead*, not gospel: verify
before acting on it, and say when you could not.

## 1. The dialect is not one thing

| Region | Cities | Character |
|---|---|---|
| **West / Tripolitania** | Tripoli, Misrata, Zawiya, Zliten, Khoms, Gharyan, Zintan, Nalut, Sabratha, Tarhuna | Maghrebi-leaning, closest to southern Tunisian. Heaviest Italian influence. |
| **East / Cyrenaica** | Benghazi, Bayda, Derna, Tobruk, Marj, Ajdabiya, Shahhat | Bedouin-leaning, closer to Egyptian and Gulf. |
| **South / Fezzan** | Sabha, Ubari, Murzuq, Ghat | Distinct again; least documented. |

A source describes the gradient as: the east begins close to Egyptian, moderates
through the centre, and becomes more Maghrebi and "softer" in the west.

## 2. Pronunciation drives spelling variants

- **ث** — pronounced [θ] in the east from **Bayda to Amsaad**; pronounced **[t]**
  in the west *and* in the eastern strip from **Marj to Ajdabiya**.
- **ذ** — [ð] in the east; **[d]** in the west and from Marj to Ajdabiya.
- **ق** is frequently written **ڨ** or **q→g** in the west: `قاروره` → `ڨاروره`.

Consequence for a test corpus: the same product can be spelled differently
depending on where the reporter is, and both spellings are correct.

## 3. Confirmed east/west word pairs

| Meaning | West | East / Central |
|---|---|---|
| a lot | **هلبا** | **واجد** |
| what do you want | **خيرك** | **كنك** |
| I speak | — | **ندوي** (Benghazi), **نسهري** (Derna), **نحكي** (Bayda, Tobruk) |
| pasta dish | **إمبكبكة** | **مكرونة جارية** |

**برشا** is Tunisian. If it appears in a Libyan corpus it is likely wrong, or at
best western border usage — check it.

## 4. Western / Tripoli markers

`نبّي` (I want), `توا` (now), `شن` / `شني` (what), `باش` (in order to),
`هكي` (like this), `باهي` (good — general Libyan, very common).

## 5. Italian loanwords (strongest in the west)

Roughly 700 exist. Confirmed, with Arabic spellings seen in sources:

`كوجينا` cucina = kitchen · `فركيتا` / `فورشيتا` forchetta = fork ·
`كاشيك` / `شيك` = spoon · `طاسة` tazza = cup · `فاسو` vaso = jar ·
`سكفالي` scaffale = shelf · `سبيتار` = hospital · `فرينو` = brakes ·
`سباجيتي` · `لازانيا` · `فروتا` = fruit

**فرينة** (flour) is from Italian *farina* and is western.

## 6. Grocery-relevant words found

- **حكة** = علبة — a tin or box. Worth checking how common against `قوطية`.
- **دلاع** = watermelon (shared across the Maghreb).
- **قربة** = water container.
- **قوطية** = tin/can — widespread in Libya and Tunisia; confirm register.

### Attested 20 August 2026, each with a source

Copied verbatim from pages that were fetched, not written or normalised here.
Every one is recorded in `_attestation` in `countries/corpus/ly.json` with its
URL, so any of them can be argued with.

| Word | Means | Register |
|---|---|---|
| **بانقة** | the 7-litre household water jug | Universal. Also `بانقة بنزينة`, so it is a jerrycan generally, not water-specific |
| **بانقه** | same word, without the tā marbūṭa | How people actually type it |
| **ميه** | ماء | `بانقه الميه` — the colloquial spelling wins in citizen posts |
| **قنينة** | bottle / jug | A speaker in **Ubari** used this where Tripoli says بانقة. Possible north/south split, unconfirmed |
| **دستة** | a twelve-pack | A *unit of sale*, not a container. `دستة مياه` = 12 half-litre bottles |
| **كراسة** | school notebook | The retail price list uses this, **not** `دفتر` |
| **مباري** | pencil sharpener | MSA is `مبراة`. Not a catalogue item; recorded anyway |
| **ستيكة** | the sticker on a water jug | Used as the mark of genuine bottled water: *"لو تاخذها بدون ستيكر تكتشف انها مياه بئر"* — without it, you find it is well water |
| **أسطوانة غاز الطهي** | LPG cylinder | Brega's own wording, confirming §9's ruling against `قروره غاز` |
| **طهو** vs **طهي** | cooking | Both appear; §9 flagged a possible east/west split and this does not settle it |

### From one daily price card, 20 August 2026

Almasar TV's vegetable card (`t.me/almasartvlibya`, field survey in سوق الحي
الإسلامي, Tripoli) carried more usable vocabulary than any vocabulary-hunting
query has:

| Word | Means | Note |
|---|---|---|
| **دلاع وطني** | locally grown watermelon | Confirms `دلاع` in §6 **and** §9's ruling that "local" is `وطني` — not `بلدي`, not `عربي` |
| **قلعاوي** | a Libyan pepper variety | Absent from the corpus entirely |
| **تين شوكي / هندي** | prickly pear | The card gives both names as interchangeable |
| **بصل يابس** | dry onion | A state used as the product name, against `بصل أخضر` |
| **الربطة** | the bunch | A unit of sale. Matches the Ministry bulletin's `ربطة` for herbs |
| **سلاطة خضراء** | lettuce | `سلاطة`, where MSA is `خس` |
| **معدنوس** | parsley | Not `بقدونس` |
| **طماطم "شمسي"** | sun-ripened tomatoes | A grade marker, alongside `درجة أولى` |
| **عنب مصري** | Egyptian grapes | Origin as part of the product name, like `زيت زيتون مستورد` |

All eleven are now in `_attestation`, and the non-basket ones are real
distractors — a category an invented list cannot supply, because inventing a
plausible Libyan vegetable nobody sells is easy and inventing `قلعاوي` is not.

**A ruling this closed.** `دبة` was removed as a water container in August after
a speaker said it means *bear* or *fat*, leaving the item with no word for the
jug at all. `بانقة` is that word — and the size was wrong too. No Libyan bottler
sells a 20-litre format: Al-Safia publishes 0.33L, 0.5L, 1.5L, **7L**, **15L**
and 230ml cups. The catalogue item is now `drinking_water_7l`.

## 6b. Prices are a dialect source

Chasing a wrong water price produced more attested vocabulary than any query
aimed at vocabulary did. Price reporting forces people to name the thing, the
size and the unit in one sentence — `بانقة مياه 7 لتر وصل سعرها اليوم إلى 4
دينار` carries the word, the format and the register at once.

Search for a **price**, harvest the **words**. It is the most productive query
pattern found so far.

## 6c. Facebook is where the language actually is

Libya's retail runs on Facebook. Not a marketplace app, not a chain's website —
pharmacy pages, stationery shops, wholesalers in Misrata posting a carton price
and a phone number. One sweep here produced more usable vocabulary than every
previous method in this brief combined, because these are people selling things
to their neighbours in the words their neighbours use.

**Method.** Bright Data over Google, `site:facebook.com` plus a Libyan-dialect
term, and — this is the part that matters — `after:2026-07-01`. Without the date
filter the results are dominated by 2020 posts that read exactly like today's.

### Three rules learned the hard way

**A snippet may never be cited for a price.** Google's snippet for one تسنيم
الطبية post read *"سعر العلبة 400 جرام .. 24 دينار"*. The post, fetched, is
dated **30 May 2020** and says *"تخفيض سعر حليب النستوجين 1 , 2 بسعر 6 دينار
للعلبة"*. Google had merged text from other posts on the same page. Fetch the
post, read its date, or do not use the number.

**Words keep, prices rot.** That 2020 post is useless as a price and perfectly
good as a wording. Harvest vocabulary from any date; harvest prices only from
dated recent posts.

**Libya-targeted results are not all Libyan.** One sweep returned *"138 ريال"*
and a school bag *"بسعر 50 ألف"* — neither is a dinar magnitude. Every wording
needs a Libyan-page anchor before it enters the corpus. This is the same trap as
the Jordanian بانقا result in §8.

### What the sweep found

| Word | Means | Why it matters |
|---|---|---|
| **بيرو**, pl. **بيروات** | ballpoint pen | Italian *biro*. The catalogue had `ballpoint_pen` and **not one Libyan word for it** |
| **الباكو** | the pack | The retail unit for pills and pads: *"الادول الباكو ب3 دينار"* |
| **الشريط** | the blister strip | The other medicine unit: *"الشريط ب1,5"* — so a باكو is two شرايط |
| **قرش** | 1/100 dinar | Sub-dinar prices are spoken in قروش: *"بيرواااات 20 قرش"*, *"بيرو 3 بـ ربع دينار"* |
| **شنطة مدرسة**, pl. **شناتي** | school bag | What people say; the ads write حقيبة مدرسية |
| **الجرار** | the wheeled one | *"الحقائب المدرسية ذات العجلات (الجرار)"* |
| **شنطة روضة** | nursery bag | A distinct product at a distinct price, 10 dinar |
| **فردة** | one loaf | *"الخبزة اتكون 5 فردات بدينار"* |
| **قنطار** | sack | Both flour and cement are priced للقنطار |
| **الكيسة** | the sack, colloquially | *"الدقيق 50 د.ل الكيسة"* |
| **مفرق** | retail | Against جملة. *"جملة ومفرق"* |
| **سعر حرق** | a burn price | Rock-bottom |
| **يابلاش** | dirt cheap | Also a shop name, which is how idiom gets into a catalogue |
| **ليلاس** | the dominant pad brand | With **بأجنحة / بدون أجنحة** |
| **الادول** | the everyday paracetamol | Not بنادول |
| **برزاني** | heavy drawing paper | Likely *Bristol*, another Italian-route loan |

### Medicine is named by colour, not by molecule

> *"البنادول ال24 حبة الحمر ب6 والازرق ب4"*

الأحمر is Extra (paracetamol + caffeine); الأزرق is Advance. A pharmacy in
صيدلية السكة prices *"بنادول ادفانس الازرق"* and *"بنادول اكسترا الاحمر"* in one
breath. A matcher keyed on active ingredients will never see any of this, and a
reporter typing "الأحمر" is not being vague — they are naming the product
precisely, in the only naming system their shop uses.

### Numbers are spoken, and Libyan numerals are their own thing

> *"علبة الزيت بطنعش تلتاشر دينار"* — the tin of oil at twelve-thirteen

اطنعش 12, تلتاشر 13, **بزوز** two (*"دينار وبزوز وتلاتة"*). A price field that
only accepts digits will silently drop the half of a report where the number is
a word. This is not a nice-to-have: it is the most common way a price is said
out loud.

### كراسة is not دفتر, and the gap is 7×

Two independent Tripoli stationers ran the same offer in August 2026 — **five
دفاتر of 80 ورقة for one dinar**, 0.20 each. A third page prices *"كراسة 60
ورقة"* at **1.25**, and قرطاسية ماتا markets a *"كراسة 80 ورقة دبل غلاف"* as a
premium line. So دفتر is the cheap stapled exercise book and كراسة the bound
one — except the same pages also use دفتر for sketchbooks and 180-sheet ledgers,
so the split is not clean.

`school_notebook_80p` lists both words as variants and prices the result at
2.30. Until someone settles which word names the book a Libyan primary
schoolchild is actually required to buy, that is a price for an unspecified
object. Logged in `_open_questions`; it needs a native speaker, not another
search.

### A note on what was not collected

Every wording here was taken from a public commercial post and recorded without
the poster. No names, no phone numbers, no profile links, no linking of a person
to a purchase — `docs/do-no-harm.md` commits to taking the words and the prices
and leaving the people, and a shop advertising a price is the one case where
that is straightforward.

## 7. Real Libyan commercial context (verified)

- Mobile operators, seen on a live Libyan store: **المدار**, **ليبيانا**,
  **ال تي تي** (LTT). Phone-credit cards are `كروت`.
- Currency: **دينار ليبي**, written **د.ل**. Subdivided into 1000 **درهم**, not
  100 — so three decimal places.
- LPG: **البريقة** (Brega) is the real distributor.
- Live Libyan e-commerce: **ly.opensooq.com** (السوق المفتوح),
  **matjar-libya.com**.
- ~~**big.ly** has فواكة، خضروات، مواد غذائية، حلويات~~ — **no longer true.**
  Fetched 20 August 2026: the live catalogue is phone cards, vouchers and
  services only, and `/kids` is empty. Search results still show grocery pages
  with prices — a Primalac 400g tin at 45.00 د.ل — but those are **stale index
  entries for products the shop no longer lists**, and that figure was very
  nearly used as a reference price. Treat a price in a search snippet as a lead
  to verify, never as a source.
- **البريقة** (Brega) publishes official LPG prices directly: 11kg refill 1.50
  د.ل, 15kg 2.00. The street price is around 60, which is worth knowing before
  trusting either number alone.

## 8. How to research, given the platforms are closed

**facebook.com and x.com cannot be fetched directly.** Tested: Facebook returns
a login wall and X returns an empty app shell. Do not waste turns on them.

What works instead:

1. **WebSearch surfaces their content in snippets.** A search for
   `بقالة مواد غذائية بنغازي طرابلس` returned a Facebook group's Arabic title.
   Use `site:facebook.com <arabic terms>` and read the titles and snippets.
2. **Fetch accessible Libyan sites**: `big.ly`, `ly.opensooq.com`,
   `matjar-libya.com`, Libyan news (`eanlibya.com`), forums, and the dialect
   dictionaries `mo3jam.com/dialect/Libyan` and `addarij.com/country/ليبيا`.
3. **Search in Arabic, not English.** English queries return tourist pages.

### Query patterns that worked

```
مواد غذائية <city> أسعار
site:facebook.com سوبر ماركت طرابلس
site:facebook.com بقالة بنغازي
أسعار السلع في ليبيا اليوم
<product> سعر ليبيا دينار
معنى كلمة <word> باللهجة الليبية
اللهجة الليبية الشرقية كلمات
```

Added 20 August 2026, after these outperformed everything above:

```
سعر <product> ليبيا 2026            # a price query returns the word AND the price
"<dialect word>" سعر دينار ليبيا     # quote the candidate to test whether it is real
أسعار <category> في ليبيا اليوم <month> 2026
"اقتصاد المسار" رصد ميداني أسعار     # finds the field surveys themselves
```

The quoted-word pattern is the useful one for verification: search `"بانقة"
سعر دينار ليبيا` and either Libyans are using the word about money or they are
not. It separates a word that exists from a word a model produced, which is the
distinction this whole file is for.

## 9. Ground truth from a Libyan speaker — read this twice

These came from a native speaker AFTER a full research pass had already "fixed"
the corpus. They are here because of what they show about how wrong a confident,
well-argued answer can be.

| The corpus had | Research changed it to | A Libyan says |
|---|---|---|
| `بلدي` (free-range) | `عربي` | **`وطني`** |
| `قروره غاز` | kept as authentic | **`اسطوانة`, `بمبلة`, `بومبة`** |
| `محفظة` (wallet) | deleted, "means wallet in Libya" | wallet is **`جزدان` / `تزدان`** |

The first is the one to sit with. Research correctly identified `بلدي` as
Egyptian register and replaced it with `عربي` — **also wrong**, with a fluent
justification attached. Detecting that a word is foreign is something sources can
do. Knowing which word is *right* needs a speaker.

`قارورة` is still correct for a bottle — cooking oil, medicine. It is only wrong
for a gas cylinder.

Attested by search, so you can trust these: `بمبلة غاز للبيع` and
`بومبة غاز منزلية` both appear as live listings on OpenSooq Libya, and Libyan
news uses `بومبة غاز` for the Brega cylinder booking system.

## 10. Sources: what works, what does not

**Works**
- **WebSearch is the workhorse.** It surfaces OpenSooq and Facebook content in
  titles and snippets even where fetching the site fails.
- **A scraping proxy changes some of this and not all of it.** With one
  configured (Bright Data, added 19 August), `almashhadlibya.com`,
  `lananews.com`, `libyaalahrar.tv` and `big.ly` all fetch cleanly where a
  plain request would be blocked. `ly.opensooq.com` still yields nothing
  useful — it is client-rendered, so the proxy returns the shell without the
  listings. Facebook remains a login wall. **The snippets are still where the
  dialect is.**
- **اقتصاد المسار (Almasar Economy)** — the single most useful Libyan price
  source found. A Libyan economics desk running dated field surveys — رصد
  ميداني — of Tripoli retail, republished as articles by `almashhadlibya.com`.
  It prices bottled water, school supplies, bread, fuel, cement, chicken and
  eggs: most of what WFP and the Ministry bulletin miss. See
  [data-sources.md](data-sources.md).
- `ly.sogarab.com` — Libyan classifieds, reachable.
- `big.ly`, `matjar-libya.com` — reachable but thin on groceries.
- `libyaakhbar.com`, `eanlibya.com` — Libyan news, quotes market prices.

**Blocked — do not spend turns here**
- `facebook.com`, `x.com` — login wall and empty app shell. HTTP 200 either way,
  which is why they look fetchable.
- `ly.opensooq.com` — **403 to a direct fetch**, but its listings appear in
  WebSearch results. Search it; do not fetch it.
- `html.duckduckgo.com` — CAPTCHA. `mojeek.com` — 403. Bing ignores Arabic
  site: queries.
- `mo3jam.com` — entry titles only, no definitions.

## 11. What the corpus needs from you

The file is `countries/corpus/ly.json`. Its `items` map catalogue codes to
wordings a person might type into a price-reporting app. It has **no regional
dimension at all** — every wording is presented as simply "Libyan", which for a
country with two clearly distinct dialects is a real gap.
