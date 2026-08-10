# Product matching evaluation

Queries: **371**

| Metric | Value |
|---|---|
| Top-1 accuracy | 98.4% |
| Top-3 accuracy | 99.7% |
| Top-5 accuracy | 99.7% |
| Mean reciprocal rank | 0.991 |

## Routing

| Outcome | Share | Precision |
|---|---|---|
| Auto-resolved | 36.1% | 99.3% |
| Sent to review | 63.9% | — |
| Rejected | 0.0% | — |

Auto-resolve precision is the number that matters most: it is the share
of matches the system accepted *without a human* that were correct.
Everything routed to review is a cost, not an error.

## Accuracy by perturbation

| Perturbation | Top-1 accuracy |
|---|---|
| head_noun_only | 89.3% |
| absorbed_by_normalisation | 100.0% |
| typo | 100.0% |
| reordered_tokens | 100.0% |

## Caveat

These figures are measured on **synthetically perturbed catalogue variants**, not on real reporter submissions. They characterise robustness to known classes of noise (dropped hamza, Arabic-Indic digits, typos, head-noun queries, token reordering) and should be read as an upper bound: real submissions contain dialect, brand names and item descriptions this set does not model. Replace with held-out human-reviewed decisions once enough have accumulated.

## Semantic matching: wired, and what it does and does not buy

Until now the semantic half of the hybrid matcher was implemented but never
switched on — `api/matching.py` constructed the matcher with `embedder=None`
and left `CatalogueIndexes.semantic` empty, so every published figure was a
lexical-only result. That is now wired: the embedder loads once per process,
and each catalogue is embedded once and cached under a fingerprint of its
variants (re-embedding per request would dominate the cost of matching, which
is very likely why it was left off).

**It works.** 133 variants embed, and the index answers sensibly — `حليب اطفال`
returns `infant_formula_400g` at 0.925 cosine.

**It does not improve the benchmark, at all:**

| Metric | Lexical | Hybrid | Δ |
|---|---|---|---|
| Top-1 | 0.9838 | 0.9838 | +0.0000 |
| Top-3 | 0.9973 | 0.9973 | +0.0000 |
| Auto-resolve precision | 0.9925 | 0.9925 | +0.0000 |

Identical to four decimal places, and the reason is the benchmark rather than
the model. The labelled set perturbs catalogue variants in exactly the ways
`token_set_ratio` already absorbs — dropped hamza, Arabic-Indic digits, typos,
token reordering, head-noun queries. At 98.4% there is almost no headroom, and
what remains is not the kind of error an embedding fixes. **This benchmark
cannot distinguish the two matchers.**

Probing where lexical should genuinely struggle — paraphrases sharing few
characters with any variant — both score 5 of 6. One difference is worth
noting: `لبن الرضاعة للاطفال` ("milk for nursing infants") moves from
**reject** to **review**. A rejected submission is data thrown away; a reviewed
one reaches a human. That is a real gain, on a sample far too small to quantify.

**So the honest position:** the semantic path is live and demonstrably
functional, and no measured accuracy improvement can be claimed on the evidence
available. Establishing whether it earns its ~1 GB of weights needs a benchmark
built from held-out human-reviewed decisions on real submissions — dialect,
brand names and free-text descriptions — which is precisely what the synthetic
set does not model. Until that exists, treat the hybrid as unproven rather than
better, and note that `QEEMA_LOAD_MODELS_ON_STARTUP=false` runs the lexical
path alone at no measured cost in accuracy.
