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
