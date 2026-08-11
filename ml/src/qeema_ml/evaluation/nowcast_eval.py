# SPDX-License-Identifier: Apache-2.0
"""Backtest for price nowcasting.

Split **temporally**, never randomly. A random split lets the model see prices
from the same week it is asked to predict, which in a series this autocorrelated
is close to showing it the answer. The reported error would be excellent and
meaningless.

Two numbers matter, and the second is the one usually omitted:

- **MAPE / pinball loss** — how close the estimate is.
- **Interval coverage** — how often the true value actually falls inside the
  claimed 80% band. An interval that claims 80% and delivers 55% is worse than
  no interval at all, because it invites false confidence.

Run: ``python -m qeema_ml.evaluation.nowcast_eval --dataset <file.json>``
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, dataclass
from pathlib import Path

import numpy as np

from qeema_ml.nowcast.model import (
    NOMINAL_COVERAGE,
    QUANTILES,
    Imputation,
    NowcastFeatures,
    NowcastModel,
    impute,
)


@dataclass
class NowcastReport:
    n_train: int
    n_test: int
    model_trained: bool
    mae: float
    mape: float
    median_ape: float
    pinball_loss: float
    interval_coverage: float
    nominal_coverage: float
    mean_relative_width: float
    fallback_share: float
    baseline_mape: float
    caveat: str

    def to_markdown(self) -> str:
        improvement = (
            (self.baseline_mape - self.mape) / self.baseline_mape * 100
            if self.baseline_mape > 0
            else 0.0
        )

        return "\n".join(
            [
                "# Price nowcasting evaluation",
                "",
                f"Temporal split — {self.n_train:,} training rows, {self.n_test:,} held-out rows.",
                f"Model trained: **{'yes' if self.model_trained else 'no'}**",
                "",
                "| Metric | Value |",
                "|---|---|",
                f"| MAE | {self.mae:,.4f} |",
                f"| MAPE | {self.mape:.1%} |",
                f"| Median APE | {self.median_ape:.1%} |",
                f"| Pinball loss | {self.pinball_loss:.4f} |",
                f"| Fallback share | {self.fallback_share:.1%} |",
                "",
                "## Interval honesty",
                "",
                "| Metric | Value |",
                "|---|---|",
                f"| Nominal coverage | {self.nominal_coverage:.0%} |",
                f"| **Empirical coverage** | **{self.interval_coverage:.1%}** |",
                f"| Mean relative width | {self.mean_relative_width:.1%} |",
                "",
                "Empirical coverage is the number that decides whether the interval",
                "can be trusted. An interval claiming 80% and delivering 55% invites",
                "exactly the false confidence this platform exists to avoid.",
                "",
                "The band is drawn at the outer quantiles in QUANTILES and",
                "published as the nominal coverage above — deliberately wider than",
                "it claims, so that it over-covers rather than under-covers.",
                "",
                "## Against the obvious baseline",
                "",
                "| Approach | MAPE |",
                "|---|---|",
                f"| National median (baseline) | {self.baseline_mape:.1%} |",
                f"| Nowcast model | {self.mape:.1%} |",
                f"| Improvement | {improvement:+.1f}% |",
                "",
                "A model that cannot beat the national median is not worth its",
                "complexity, so the baseline is reported alongside rather than",
                "left for a reader to wonder about.",
                "",
                "## Caveat",
                "",
                self.caveat,
                "",
            ]
        )


def _pinball(actual: float, predicted: float, quantile: float) -> float:
    delta = actual - predicted

    return max(quantile * delta, (quantile - 1) * delta)


def evaluate(rows: list[dict], test_fraction: float = 0.2) -> NowcastReport:
    """Train on the earlier portion, score on the later one."""
    ordered = sorted(rows, key=lambda r: str(r["date"]))
    split = int(len(ordered) * (1 - test_fraction))
    train_rows, test_rows = ordered[:split], ordered[split:]

    def to_features(row: dict) -> NowcastFeatures:
        return NowcastFeatures(**{k: float(row[k]) for k in NowcastFeatures.names()})

    model = NowcastModel()

    # Targets are ratios to the national median so one model serves every item.
    train_features = [to_features(r) for r in train_rows]
    train_targets = [
        float(r["actual"]) / float(r["national_median"])
        for r in train_rows
        if float(r["national_median"]) > 0
    ]
    train_features = [
        f
        for f, r in zip(train_features, train_rows, strict=True)
        if float(r["national_median"]) > 0
    ]

    trained = model.fit(train_features, train_targets)

    errors: list[float] = []
    apes: list[float] = []
    pinballs: list[float] = []
    inside = 0
    widths: list[float] = []
    fallbacks = 0
    baseline_apes: list[float] = []
    scored = 0

    for row in test_rows:
        actual = float(row["actual"])

        if actual <= 0:
            continue

        features = to_features(row)
        prediction: Imputation | None = impute(model if trained else None, features)

        if prediction is None:
            continue

        scored += 1
        errors.append(abs(prediction.value - actual))
        apes.append(abs(prediction.value - actual) / actual)
        widths.append(prediction.relative_width)

        if prediction.is_fallback:
            fallbacks += 1

        if prediction.lower <= actual <= prediction.upper:
            inside += 1

        for quantile, predicted in (
            (QUANTILES[0], prediction.lower),
            (QUANTILES[len(QUANTILES) // 2], prediction.value),
            (QUANTILES[-1], prediction.upper),
        ):
            pinballs.append(_pinball(actual, predicted, quantile))

        national = float(features.national_median)
        if national > 0:
            baseline_apes.append(abs(national - actual) / actual)

    n = max(1, scored)

    return NowcastReport(
        n_train=len(train_features),
        n_test=scored,
        model_trained=trained,
        mae=float(np.mean(errors)) if errors else 0.0,
        mape=float(np.mean(apes)) if apes else 0.0,
        median_ape=float(np.median(apes)) if apes else 0.0,
        pinball_loss=float(np.mean(pinballs)) if pinballs else 0.0,
        interval_coverage=inside / n,
        nominal_coverage=NOMINAL_COVERAGE,
        mean_relative_width=float(np.mean(widths)) if widths else 0.0,
        fallback_share=fallbacks / n,
        baseline_mape=float(np.mean(baseline_apes)) if baseline_apes else 0.0,
        caveat=(
            "Backtested on the synthetic six-month history, whose price process "
            "the generator defined — inflation, FX pass-through, a regional "
            "premium and decaying shocks. A model evaluated on data generated by "
            "known rules will do better than one facing real markets, so read "
            "these as an upper bound. The interval-coverage figure is the more "
            "transferable of the two: a model that cannot calibrate its own "
            "uncertainty on easy data will not manage it on hard data."
        ),
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Backtest price nowcasting.")
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--out-json", type=Path, default=Path("artifacts/nowcast-eval.json"))
    parser.add_argument("--out-markdown", type=Path, default=Path("artifacts/nowcast-eval.md"))
    parser.add_argument("--max-mape", type=float, default=1.0)

    args = parser.parse_args(argv)

    with args.dataset.open(encoding="utf-8") as handle:
        rows = json.load(handle)

    report = evaluate(rows)

    args.out_json.parent.mkdir(parents=True, exist_ok=True)
    args.out_json.write_text(json.dumps(asdict(report), indent=2, ensure_ascii=False), "utf-8")
    args.out_markdown.write_text(report.to_markdown(), "utf-8")

    print(report.to_markdown())

    if report.mape > args.max_mape:
        print(f"\nFAIL: MAPE {report.mape:.1%} above allowed {args.max_mape:.1%}", file=sys.stderr)

        return 1

    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
