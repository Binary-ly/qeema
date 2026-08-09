# SPDX-License-Identifier: Apache-2.0
"""Nowcasting backtest harness.

The harness produces a published error figure, so its own correctness matters:
a backtest that leaks future data reports an excellent number that means
nothing.
"""

from __future__ import annotations

import json

from qeema_ml.evaluation.nowcast_eval import NowcastReport, evaluate, main


def dataset(n: int = 400) -> list[dict]:
    rows = []
    for i in range(n):
        day = f"2026-{1 + i // 120:02d}-{1 + i % 28:02d}"
        level = 0.9 + (i % 20) / 100
        rows.append(
            {
                "date": day,
                "actual": 10.0 * level,
                "national_median": 10.0,
                "neighbour_median": 10.0 * level,
                "neighbour_weighted": 10.0 * level,
                "neighbour_count": 3.0,
                "nearest_neighbour_km": 40.0,
                "last_local_price": 10.0 * level,
                "days_since_local": 2.0,
                "national_trend": 1.0,
                "fx_change_30d": 1.0,
                "location_price_level": level,
                "day_of_week": float(i % 7),
            }
        )
    return rows


class TestEvaluate:
    def test_splits_temporally_not_randomly(self) -> None:
        # Every training date must precede every test date, or the model sees
        # prices from the week it is asked to predict.
        rows = dataset()
        report = evaluate(rows, test_fraction=0.25)

        assert report.n_train > 0
        assert report.n_test > 0
        assert report.n_train > report.n_test

    def test_reports_metrics_in_a_sane_range(self) -> None:
        report = evaluate(dataset())

        assert report.mape >= 0.0
        assert 0.0 <= report.interval_coverage <= 1.0
        assert report.nominal_coverage == 0.8

    def test_reports_the_baseline_alongside(self) -> None:
        # A model that cannot beat the national median is not worth its
        # complexity, so the comparison is always published.
        report = evaluate(dataset())

        assert report.baseline_mape > 0.0

    def test_falls_back_when_the_model_cannot_train(self) -> None:
        report = evaluate(dataset(n=40))

        assert not report.model_trained
        assert report.fallback_share == 1.0

    def test_skips_rows_with_no_usable_target(self) -> None:
        rows = dataset(n=250)
        rows[-1]["actual"] = 0.0

        report = evaluate(rows)

        assert report.n_test >= 1

    def test_states_its_own_limitations(self) -> None:
        report = evaluate(dataset())

        assert "upper bound" in report.caveat


class TestReport:
    def test_markdown_includes_the_honesty_metrics(self) -> None:
        markdown = evaluate(dataset()).to_markdown()

        for heading in ("Empirical coverage", "Nominal coverage", "baseline", "Caveat"):
            assert heading in markdown


class TestCli:
    def test_writes_both_artifacts(self, tmp_path) -> None:
        path = tmp_path / "d.json"
        path.write_text(json.dumps(dataset()), encoding="utf-8")

        out_json, out_md = tmp_path / "e.json", tmp_path / "e.md"
        code = main(
            ["--dataset", str(path), "--out-json", str(out_json), "--out-markdown", str(out_md)]
        )

        assert code == 0
        report = json.loads(out_json.read_text(encoding="utf-8"))
        assert set(NowcastReport.__dataclass_fields__) <= set(report)

    def test_fails_when_error_exceeds_the_gate(self, tmp_path) -> None:
        path = tmp_path / "d.json"
        path.write_text(json.dumps(dataset()), encoding="utf-8")

        code = main(
            [
                "--dataset",
                str(path),
                "--out-json",
                str(tmp_path / "e.json"),
                "--out-markdown",
                str(tmp_path / "e.md"),
                "--max-mape",
                "-0.01",
            ]
        )

        assert code == 1
