# SPDX-License-Identifier: Apache-2.0
"""Service-side contract tests.

The *real* FastAPI responses are validated here against
``contracts/ml-match-response.json``. The PHP suite validates its test double
against the very same file.

That pairing is the whole mechanism. A hand-written fake and the service it
stands in for drift apart over time; every test keeps passing, and the drift is
discovered in production. Sharing one schema means a change to either side that
is not reflected in the contract breaks a build.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from jsonschema import Draft202012Validator

CONTRACT_PATH = Path(__file__).resolve().parents[2] / "contracts" / "ml-match-response.json"

CATALOGUE = {
    "variants": [
        {
            "canonical_item_id": 1,
            "canonical_item_code": "infant_formula_400g",
            "text": "حليب أطفال",
            "normalized_text": "حليب اطفال",
        },
        {
            "canonical_item_id": 2,
            "canonical_item_code": "rice_1kg",
            "text": "أرز",
            "normalized_text": "ارز",
        },
        {
            "canonical_item_id": 2,
            "canonical_item_code": "rice_1kg",
            "text": "rice",
            "normalized_text": "rice",
        },
    ]
}


@pytest.fixture(scope="module")
def validator() -> Draft202012Validator:
    assert CONTRACT_PATH.exists(), f"Shared contract missing at {CONTRACT_PATH}"

    with CONTRACT_PATH.open(encoding="utf-8") as handle:
        schema = json.load(handle)

    Draft202012Validator.check_schema(schema)

    return Draft202012Validator(schema)


def assert_valid(validator: Draft202012Validator, payload: dict) -> None:
    errors = sorted(validator.iter_errors(payload), key=lambda e: e.path)

    assert not errors, "\n".join(
        f"{'/'.join(str(p) for p in e.path) or '<root>'}: {e.message}" for e in errors
    )


class TestMatchContract:
    def test_an_exact_match_satisfies_the_contract(
        self, client: TestClient, validator: Draft202012Validator
    ) -> None:
        response = client.post("/v1/match", json={"text": "حليب اطفال", "catalogue": CATALOGUE})

        assert response.status_code == 200
        assert_valid(validator, response.json())

    def test_a_fuzzy_match_satisfies_the_contract(
        self, client: TestClient, validator: Draft202012Validator
    ) -> None:
        response = client.post("/v1/match", json={"text": "حليب اطفل", "catalogue": CATALOGUE})

        assert response.status_code == 200
        assert_valid(validator, response.json())

    def test_a_no_match_satisfies_the_contract(
        self, client: TestClient, validator: Draft202012Validator
    ) -> None:
        # The empty-candidate shape is the one most likely to be modelled
        # wrongly by a test double.
        response = client.post("/v1/match", json={"text": "zzzz qqqq wwww", "catalogue": CATALOGUE})

        assert response.status_code == 200
        assert_valid(validator, response.json())

    def test_every_batch_result_satisfies_the_contract(
        self, client: TestClient, validator: Draft202012Validator
    ) -> None:
        response = client.post(
            "/v1/match/batch",
            json={"texts": ["حليب اطفال", "ارز", "nonsense"], "catalogue": CATALOGUE},
        )

        assert response.status_code == 200

        for result in response.json()["results"]:
            assert_valid(validator, result)


class TestMatchBehaviour:
    def test_resolves_an_exact_variant(self, client: TestClient) -> None:
        body = client.post("/v1/match", json={"text": "حليب اطفال", "catalogue": CATALOGUE}).json()

        assert body["action"] == "auto_resolve"
        assert body["candidates"][0]["canonical_item_id"] == 1

    def test_normalises_before_matching(self, client: TestClient) -> None:
        # Hamza and Arabic-Indic digits must not defeat an exact match.
        body = client.post("/v1/match", json={"text": "حليب أطفال", "catalogue": CATALOGUE}).json()

        assert body["normalised_text"] == "حليب اطفال"
        assert body["action"] == "auto_resolve"

    def test_reports_that_it_is_uncalibrated(self, client: TestClient) -> None:
        # A caller must be able to tell that a confidence figure is not yet
        # backed by observed outcomes.
        body = client.post("/v1/match", json={"text": "ارز", "catalogue": CATALOGUE}).json()

        assert body["calibrated"] is False

    def test_rejects_an_empty_catalogue_rather_than_matching_nothing(
        self, client: TestClient
    ) -> None:
        response = client.post("/v1/match", json={"text": "ارز", "catalogue": {"variants": []}})

        assert response.status_code == 422

    def test_rejects_empty_text(self, client: TestClient) -> None:
        response = client.post("/v1/match", json={"text": "", "catalogue": CATALOGUE})

        assert response.status_code == 422


class TestCalibrationEndpoint:
    def test_refuses_to_calibrate_on_too_little_data(self, client: TestClient) -> None:
        response = client.post(
            "/v1/match/calibrate", json={"scores": [0.9, 0.2], "correct": [True, False]}
        )

        assert response.status_code == 200
        body = response.json()
        assert body["fitted"] is False
        assert "at least" in body["reason"]

    def test_rejects_mismatched_lengths(self, client: TestClient) -> None:
        response = client.post(
            "/v1/match/calibrate", json={"scores": [0.9, 0.2], "correct": [True]}
        )

        assert response.status_code == 422
