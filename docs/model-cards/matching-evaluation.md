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
