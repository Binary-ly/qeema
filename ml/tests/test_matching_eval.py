# SPDX-License-Identifier: Apache-2.0
"""Evaluation harness.

The harness produces the accuracy figure the project publishes, so its own
correctness matters: a harness that quietly inflates its numbers is worse than
no harness at all.
"""

from __future__ import annotations

import json

import pytest

from qeema_ml.evaluation.matching_eval import (
    EvaluationReport,
    build_labelled_set,
    evaluate,
    main,
)

CATALOGUE = [
    {
        "canonical_item_id": 1,
        "canonical_item_code": "infant_formula_400g",
        "variants": ["حليب أطفال 400 غرام", "حليب اطفال", "baby milk", "infant formula"],
    },
    {
        "canonical_item_id": 2,
        "canonical_item_code": "rice_1kg",
        "variants": ["أرز", "ارز ابيض", "rice", "white rice"],
    },
    {
        "canonical_item_id": 3,
        "canonical_item_code": "cooking_gas_11kg",
        "variants": ["أسطوانة غاز", "بوتاجاز", "gas cylinder"],
    },
]


class TestLabelledSet:
    def test_generates_queries_for_every_item(self) -> None:
        queries = build_labelled_set(CATALOGUE)
        covered = {q.expected_item_id for q in queries}

        assert covered == {1, 2, 3}

    def test_is_deterministic_for_a_given_seed(self) -> None:
        # Evaluation numbers must be comparable between runs.
        first = build_labelled_set(CATALOGUE, seed=7)
        second = build_labelled_set(CATALOGUE, seed=7)

        assert [q.text for q in first] == [q.text for q in second]

    def test_covers_the_perturbations_that_matter(self) -> None:
        kinds = {q.perturbation for q in build_labelled_set(CATALOGUE)}

        assert {"head_noun_only", "reordered_tokens", "typo"} <= kinds

    def test_labels_normalisation_absorbed_perturbations_honestly(self) -> None:
        # Dropping a hamza normalises back to the canonical form, so those
        # queries are not evidence of robustness and must not be credited to a
        # perturbation category as though they were.
        kinds = {q.perturbation for q in build_labelled_set(CATALOGUE)}

        assert "absorbed_by_normalisation" in kinds

    def test_deduplicates_queries(self) -> None:
        # Several variants of one item perturb to the same string; counting it
        # twice would weight that item more heavily than the others.
        queries = build_labelled_set(CATALOGUE)
        keys = [(q.text, q.expected_item_id) for q in queries]

        assert len(keys) == len(set(keys))

    def test_handles_an_item_with_no_usable_variants(self) -> None:
        queries = build_labelled_set(
            [{"canonical_item_id": 9, "canonical_item_code": "x", "variants": ["", "   "]}]
        )

        assert queries == []


class TestEvaluate:
    def test_reports_metrics_in_range(self) -> None:
        report = evaluate(CATALOGUE, build_labelled_set(CATALOGUE))

        for value in (
            report.top1_accuracy,
            report.top3_accuracy,
            report.top5_accuracy,
            report.mean_reciprocal_rank,
            report.auto_resolve_rate,
            report.auto_resolve_precision,
        ):
            assert 0.0 <= value <= 1.0

    def test_top_k_accuracy_is_monotonic(self) -> None:
        report = evaluate(CATALOGUE, build_labelled_set(CATALOGUE))

        assert report.top1_accuracy <= report.top3_accuracy <= report.top5_accuracy

    def test_routing_shares_sum_to_one(self) -> None:
        report = evaluate(CATALOGUE, build_labelled_set(CATALOGUE))
        total = report.auto_resolve_rate + report.review_rate + report.reject_rate

        assert total == pytest.approx(1.0)

    def test_does_not_reject_correct_matches_without_embeddings(self) -> None:
        # The regression this harness caught: with no semantic index, weight
        # renormalisation is what stops correct matches being thrown away.
        report = evaluate(CATALOGUE, build_labelled_set(CATALOGUE))

        assert report.top1_accuracy > 0.8
        assert report.reject_rate < 0.2

    def test_handles_an_empty_query_set(self) -> None:
        report = evaluate(CATALOGUE, [])

        assert report.n_queries == 0
        assert report.top1_accuracy == 0.0

    def test_states_its_own_limitations(self) -> None:
        # A published accuracy figure without its caveat invites the reader to
        # over-trust it.
        report = evaluate(CATALOGUE, build_labelled_set(CATALOGUE))

        assert "synthetically perturbed" in report.caveat
        assert "upper bound" in report.caveat


class TestReportRendering:
    def test_markdown_includes_every_headline_metric(self) -> None:
        markdown = evaluate(CATALOGUE, build_labelled_set(CATALOGUE)).to_markdown()

        for heading in ("Top-1 accuracy", "Auto-resolved", "Caveat", "Mean reciprocal rank"):
            assert heading in markdown


class TestCli:
    def test_writes_both_artifacts(self, tmp_path) -> None:
        catalogue_path = tmp_path / "catalogue.json"
        catalogue_path.write_text(json.dumps(CATALOGUE), encoding="utf-8")

        out_json = tmp_path / "eval.json"
        out_md = tmp_path / "eval.md"

        exit_code = main(
            [
                "--catalogue",
                str(catalogue_path),
                "--out-json",
                str(out_json),
                "--out-markdown",
                str(out_md),
            ]
        )

        assert exit_code == 0
        assert out_json.exists() and out_md.exists()

        report = json.loads(out_json.read_text(encoding="utf-8"))
        assert set(EvaluationReport.__dataclass_fields__) <= set(report)

    def test_fails_when_accuracy_falls_below_the_gate(self, tmp_path) -> None:
        # Usable as a CI gate: a regression in matching should break the build,
        # not quietly ship.
        catalogue_path = tmp_path / "catalogue.json"
        catalogue_path.write_text(json.dumps(CATALOGUE), encoding="utf-8")

        exit_code = main(
            [
                "--catalogue",
                str(catalogue_path),
                "--out-json",
                str(tmp_path / "e.json"),
                "--out-markdown",
                str(tmp_path / "e.md"),
                "--min-top1",
                "1.01",
            ]
        )

        assert exit_code == 1
