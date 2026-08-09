# Anomaly detection evaluation

Observations: **10,675** (10,099 clean, 576 labelled bad)

| Metric | Value |
|---|---|
| Overall recall | 68.6% |
| Precision | 74.8% |
| False-positive rate on clean data | 1.3% |
| Isolation forest trained | yes |

The false-positive rate matters as much as recall. Every clean
submission wrongly flagged is a reviewer's minute spent, and a
detector that flags everything has perfect recall and no value.

## Recall by class

| Class | Labelled | Detected | Recall |
|---|---|---|---|
| Erroneous (honest mistakes) | 501 | 391 | 78.0% |
| Manipulated (coordinated) | 75 | 4 | 5.3% |

## Recall by injected error type

| Error type | Labelled | Detected | Recall |
|---|---|---|---|
| decimal_slip | 105 | 105 | 100.0% |
| stale_copy | 125 | 16 | 12.8% |
| unit_confusion | 136 | 136 | 100.0% |
| wrong_currency | 135 | 134 | 99.3% |

## Caveat

Measured against **synthetically injected** errors whose form the generator chose, so these figures describe detection of *those* failure modes rather than of real-world bad data. Coordinated manipulation in particular is generated with a fixed suppression band; a real adversary would adapt. Treat the honest-mistake recall as broadly indicative and the manipulation recall as an optimistic bound.

## Reporter-level bias (fourth layer)

The observation-level layers above catch **5.3%** of coordinated manipulation.
That is structural, not a tuning problem: the manipulated prices are individually
plausible, so no test looking at one observation at a time can separate them from
genuinely cheaper shops.

A reporter-level layer closes it. Measured on the same labelled data, profiling
64 reporters:

| Metric | Value |
|---|---|
| Manipulator recall | **100%** (4 of 4) |
| Precision | 80% (1 false positive) |
| Separation | manipulators at z −22 to −14; false positive at −3.3; next honest −2.2 |

**The statistic mattered more than the model.** A first version using the *median*
ratio scored **0%**: manipulators had a median of 0.995 against honest reporters'
1.001. Only ~12% of a manipulator's submissions are falsified, so the median is
dominated by their honest majority — it is robust against exactly the signal
being hunted. Switching to the **lower decile**, where partial manipulation
lives, separates cleanly: every manipulator at or below 0.746, every honest
reporter at or above 0.900.

Flagged reporters go to human review, never to automatic rejection. Accusing
someone of manipulation on statistical evidence and silently discarding their
work is a decision a person should make.
