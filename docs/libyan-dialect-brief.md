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

## 7. Real Libyan commercial context (verified)

- Mobile operators, seen on a live Libyan store: **المدار**, **ليبيانا**,
  **ال تي تي** (LTT). Phone-credit cards are `كروت`.
- Currency: **دينار ليبي**, written **د.ل**. Subdivided into 1000 **درهم**, not
  100 — so three decimal places.
- LPG: **البريقة** (Brega) is the real distributor.
- Live Libyan e-commerce: **big.ly** (has فواكة، خضروات، مواد غذائية، حلويات),
  **ly.opensooq.com** (السوق المفتوح), **matjar-libya.com**.

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

## 9. What the corpus needs from you

The file is `countries/corpus/ly.json`. Its `items` map catalogue codes to
wordings a person might type into a price-reporting app. It has **no regional
dimension at all** — every wording is presented as simply "Libyan", which for a
country with two clearly distinct dialects is a real gap.
