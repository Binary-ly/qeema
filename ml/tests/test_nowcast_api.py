# SPDX-License-Identifier: Apache-2.0
"""The nowcast endpoints over HTTP, and the country boundary between models.

There used to be one module-level model for the whole service. Training Libya
and then Venezuela left only Venezuela's fit answering for both — not obviously
broken, because targets are ratios to a national median and so scale-free, but
"whichever country was trained last" is not a decision anyone made.

The tests that matter here are the isolation ones. A shared model produces
plausible numbers, which is exactly why nothing would have caught it.
"""

from __future__ import annotations

from typing import Any

from fastapi.testclient import TestClient

from qeema_ml.api.nowcast import reset_models


def features(national_median: float = 10.0, **overrides: Any) -> dict[str, Any]:
    return {
        "national_median": national_median,
        "neighbour_median": national_median,
        "neighbour_weighted": national_median,
        "neighbour_count": 3.0,
        "nearest_neighbour_km": 20.0,
        "last_local_price": national_median,
        "days_since_local": 2.0,
        "national_trend": 1.0,
        "fx_change_30d": 1.0,
        "location_price_level": 1.0,
        "day_of_week": 3.0,
        **overrides,
    }


def training_payload(country: str, ratio: float, rows: int = 400) -> dict[str, Any]:
    """Enough rows to fit, all pointing at one ratio, so the fit is obvious."""
    return {
        "country": country,
        "features": [features(national_median=10.0 + (i % 5)) for i in range(rows)],
        "targets": [ratio] * rows,
    }


class TestModelIsolation:
    def test_training_one_country_does_not_fit_another(self, client: TestClient) -> None:
        reset_models()

        client.post("/v1/nowcast/train", json=training_payload("LY", ratio=2.0))

        response = client.post(
            "/v1/nowcast/impute",
            json={"country": "VE", "requests": [features()]},
        )

        # VE has never been trained, so it must still be falling back — not
        # quietly answering out of Libya's model.
        assert response.json()["model_trained"] is False
        assert response.json()["results"][0]["method"].startswith("fallback")

    def test_each_country_answers_from_its_own_fit(self, client: TestClient) -> None:
        reset_models()

        client.post("/v1/nowcast/train", json=training_payload("LY", ratio=2.0))
        client.post("/v1/nowcast/train", json=training_payload("VE", ratio=0.5))

        ly = client.post(
            "/v1/nowcast/impute",
            json={"country": "LY", "requests": [features(national_median=10.0)]},
        ).json()["results"][0]
        ve = client.post(
            "/v1/nowcast/impute",
            json={"country": "VE", "requests": [features(national_median=10.0)]},
        ).json()["results"][0]

        # Same context, same anchor, two models fitted to opposite ratios. One
        # shared model would return the same number twice.
        assert ly["value"] > ve["value"]

    def test_the_country_code_is_case_insensitive(self, client: TestClient) -> None:
        reset_models()

        client.post("/v1/nowcast/train", json=training_payload("ly", ratio=2.0))

        response = client.post(
            "/v1/nowcast/impute", json={"country": "LY", "requests": [features()]}
        )

        assert response.json()["model_trained"] is True


class TestContract:
    def test_a_request_without_a_country_is_refused(self, client: TestClient) -> None:
        # Required rather than defaulted: a default is how one country's prices
        # end up being served from another country's model without anyone
        # choosing that.
        response = client.post("/v1/nowcast/impute", json={"requests": [features()]})

        assert response.status_code == 422

    def test_training_refuses_mismatched_features_and_targets(self, client: TestClient) -> None:
        response = client.post(
            "/v1/nowcast/train",
            json={"country": "LY", "features": [features()], "targets": [1.0, 2.0]},
        )

        body = response.json()

        assert body["trained"] is False
        assert "same length" in body["reason"]

    def test_it_declines_rather_than_fitting_noise(self, client: TestClient) -> None:
        reset_models()

        response = client.post("/v1/nowcast/train", json=training_payload("LY", ratio=1.0, rows=10))

        body = response.json()

        # A model fitted on ten rows would be memorising, and would then be
        # trusted exactly as much as one fitted on ten thousand.
        assert body["trained"] is False
        assert "at least" in body["reason"]

    def test_every_result_is_labelled_imputed(self, client: TestClient) -> None:
        reset_models()

        response = client.post(
            "/v1/nowcast/impute", json={"country": "LY", "requests": [features(), features(0.0)]}
        )

        for result in response.json()["results"]:
            assert result["is_imputed"] is True
