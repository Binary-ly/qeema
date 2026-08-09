# SPDX-License-Identifier: Apache-2.0
"""Evaluation harness for anomaly detection.

Scored against the labelled bad data the synthetic generator injected: 5%
erroneous (unit confusion, decimal slips, wrong currency, stale copies) and 2%
manipulated (a coordinated cluster reporting systematically low). The labels
live in the ``qeema_eval`` schema and never touch the published API.

The two error classes are reported **separately**, because they are different
problems and a single blended number would hide the interesting one. Injected
mistakes are mostly gross and should be caught nearly always. Coordinated
manipulation is subtle by construction — every individual figure is plausible —
and a detector that claims to catch it easily is a detector that has learned
something about this generator rather than about manipulation.

Run: ``python -m qeema_ml.evaluation.anomaly_eval --observations <file.json>``
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, dataclass
from pathlib import Path

from qeema_ml.anomaly.detector import (
    VERDICT_CLEAN,
    AnomalyDetector,
    DetectorConfig,
    Observation,
    build_features,
)


@dataclass
class ClassMetrics:
    label: str
    support: int
    detected: int
    recall: float

    def as_row(self) -> str:
        return f"| {self.label} | {self.support} | {self.detected} | {self.recall:.1%} |"


@dataclass
class AnomalyReport:
    n_observations: int
    n_clean: int
    flagged: int
    false_positive_rate: float
    precision: float
    overall_recall: float
    by_class: list[ClassMetrics]
    by_error_type: list[ClassMetrics]
    forest_trained: bool
    caveat: str

    def to_markdown(self) -> str:
        lines = [
            "# Anomaly detection evaluation",
            "",
            f"Observations: **{self.n_observations:,}** "
            f"({self.n_clean:,} clean, {self.n_observations - self.n_clean:,} labelled bad)",
            "",
            "| Metric | Value |",
            "|---|---|",
            f"| Overall recall | {self.overall_recall:.1%} |",
            f"| Precision | {self.precision:.1%} |",
            f"| False-positive rate on clean data | {self.false_positive_rate:.1%} |",
            f"| Isolation forest trained | {'yes' if self.forest_trained else 'no'} |",
            "",
            "The false-positive rate matters as much as recall. Every clean",
            "submission wrongly flagged is a reviewer's minute spent, and a",
            "detector that flags everything has perfect recall and no value.",
            "",
            "## Recall by class",
            "",
            "| Class | Labelled | Detected | Recall |",
            "|---|---|---|---|",
        ]

        lines += [m.as_row() for m in self.by_class]

        lines += [
            "",
            "## Recall by injected error type",
            "",
            "| Error type | Labelled | Detected | Recall |",
            "|---|---|---|---|",
        ]
        lines += [m.as_row() for m in self.by_error_type]
        lines += ["", "## Caveat", "", self.caveat, ""]

        return "\n".join(lines)


def _metrics(label: str, flagged: list[bool]) -> ClassMetrics:
    support = len(flagged)
    detected = sum(flagged)

    return ClassMetrics(
        label=label,
        support=support,
        detected=detected,
        recall=detected / support if support else 0.0,
    )


def evaluate(records: list[dict], config: DetectorConfig | None = None) -> AnomalyReport:
    """Score labelled observations and summarise detection performance."""
    detector = AnomalyDetector(config)

    observations = [
        Observation(
            submission_id=str(r["submission_id"]),
            price=float(r["price"]),
            local_prices=[float(p) for p in r.get("local_prices", [])],
            national_median=r.get("national_median"),
            item_reference_median=r.get("item_reference_median"),
            trend_expected=r.get("trend_expected"),
            reporter_mean_deviation=float(r.get("reporter_mean_deviation", 0.0)),
            reporter_submission_rate=float(r.get("reporter_submission_rate", 1.0)),
            hour_of_day=int(r.get("hour_of_day", 12)),
            days_since_last_local_report=float(r.get("days_since_last_local_report", 1.0)),
        )
        for r in records
    ]

    # Trained on everything, unsupervised: the point is to learn what ordinary
    # submissions look like. Training only on clean data would leak the labels
    # and flatter the result.
    detector.fit([build_features(o) for o in observations])

    verdicts = detector.score_many(observations)

    clean_flags: list[bool] = []
    erroneous_flags: list[bool] = []
    manipulated_flags: list[bool] = []
    by_error: dict[str, list[bool]] = {}

    true_positives = 0
    flagged_total = 0

    for record, verdict in zip(records, verdicts, strict=True):
        flagged = verdict.verdict != VERDICT_CLEAN
        is_erroneous = bool(record.get("is_erroneous"))
        is_manipulated = bool(record.get("is_manipulated"))

        if flagged:
            flagged_total += 1

            if is_erroneous or is_manipulated:
                true_positives += 1

        if is_manipulated:
            manipulated_flags.append(flagged)
        elif is_erroneous:
            erroneous_flags.append(flagged)
            by_error.setdefault(str(record.get("error_type") or "unknown"), []).append(flagged)
        else:
            clean_flags.append(flagged)

    bad_total = len(erroneous_flags) + len(manipulated_flags)
    bad_detected = sum(erroneous_flags) + sum(manipulated_flags)

    return AnomalyReport(
        n_observations=len(records),
        n_clean=len(clean_flags),
        flagged=flagged_total,
        false_positive_rate=sum(clean_flags) / len(clean_flags) if clean_flags else 0.0,
        precision=true_positives / flagged_total if flagged_total else 0.0,
        overall_recall=bad_detected / bad_total if bad_total else 0.0,
        by_class=[
            _metrics("Erroneous (honest mistakes)", erroneous_flags),
            _metrics("Manipulated (coordinated)", manipulated_flags),
        ],
        by_error_type=[_metrics(name, flags) for name, flags in sorted(by_error.items())],
        forest_trained=detector.forest_is_trained,
        caveat=(
            "Measured against **synthetically injected** errors whose form the "
            "generator chose, so these figures describe detection of *those* "
            "failure modes rather than of real-world bad data. Coordinated "
            "manipulation in particular is generated with a fixed suppression "
            "band; a real adversary would adapt. Treat the honest-mistake recall "
            "as broadly indicative and the manipulation recall as an optimistic "
            "bound."
        ),
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Evaluate anomaly detection.")
    parser.add_argument("--observations", type=Path, required=True)
    parser.add_argument("--out-json", type=Path, default=Path("artifacts/anomaly-eval.json"))
    parser.add_argument("--out-markdown", type=Path, default=Path("artifacts/anomaly-eval.md"))
    parser.add_argument("--min-recall", type=float, default=0.0)

    args = parser.parse_args(argv)

    with args.observations.open(encoding="utf-8") as handle:
        records = json.load(handle)

    report = evaluate(records)

    args.out_json.parent.mkdir(parents=True, exist_ok=True)
    args.out_json.write_text(json.dumps(asdict(report), indent=2, ensure_ascii=False), "utf-8")
    args.out_markdown.write_text(report.to_markdown(), "utf-8")

    print(report.to_markdown())

    if report.overall_recall < args.min_recall:
        print(
            f"\nFAIL: recall {report.overall_recall:.1%} below required {args.min_recall:.1%}",
            file=sys.stderr,
        )

        return 1

    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
