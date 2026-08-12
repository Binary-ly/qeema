# SPDX-License-Identifier: Apache-2.0
"""The anomaly endpoints over HTTP.

These exist because of a bug that survived a hundred passing tests.

``AnomalyVerdict`` is a slotted dataclass, so it has no instance dictionary,
and the endpoint built its response with ``v.__dict__``. Every call returned a
500. The detector itself was thoroughly covered — every layer, every threshold,
every quiet case — but nothing exercised the route, so the serialisation
between a correct detector and its caller was never run at all.

It stayed invisible in production for a second reason: nothing in the platform
called anomaly scoring. The moment the pipeline was wired up, the first real
request found it.

The lesson generalises past this one line. A stage can be perfect and still be
unreachable, and the tests that would notice are the ones that cross a
boundary rather than sit inside it.
"""

from __future__ import annotations

from typing import Any

from fastapi.testclient import TestClient


def observation(submission_id: str, price: float, **overrides: Any) -> dict[str, Any]:
    return {
        "submission_id": submission_id,
        "price": price,
        "local_prices": [11.5, 11.9, 12.1, 11.7],
        "item_reference_median": 11.8,
        "national_median": 11.8,
        "trend_expected": 11.8,
        "reporter_mean_deviation": 0.4,
        "reporter_submission_rate": 1.0,
        "hour_of_day": 14,
        "days_since_last_local_report": 1.0,
        **overrides,
    }


class TestScoreEndpoint:
    def test_returns_a_verdict_over_http(self, client: TestClient) -> None:
        response = client.post(
            "/v1/anomaly/score",
            json={"observations": [observation("11111111-1111-1111-1111-111111111111", 12.0)]},
        )

        assert response.status_code == 200

        body = response.json()

        assert len(body["results"]) == 1
        assert body["results"][0]["submission_id"] == "11111111-1111-1111-1111-111111111111"
        assert body["model_version"].startswith("anomaly-")

    def test_every_field_the_caller_relies_on_is_present(self, client: TestClient) -> None:
        # Laravel reads all four off each verdict. A missing key is a silently
        # unscored observation, which is worse than a loud failure.
        response = client.post(
            "/v1/anomaly/score",
            json={"observations": [observation("22222222-2222-2222-2222-222222222222", 12.0)]},
        )

        verdict = response.json()["results"][0]

        for field in ("submission_id", "score", "verdict", "reasons", "layer_scores"):
            assert field in verdict, f"verdict is missing {field}"

    def test_rejects_a_decimal_slip(self, client: TestClient) -> None:
        # A hundred times the going rate, end to end rather than in the layer.
        response = client.post(
            "/v1/anomaly/score",
            json={"observations": [observation("33333333-3333-3333-3333-333333333333", 1200.0)]},
        )

        verdict = response.json()["results"][0]

        assert verdict["verdict"] == "rejected"
        assert verdict["reasons"], "a rejection must say why"

    def test_returns_one_verdict_per_observation_in_order(self, client: TestClient) -> None:
        # The caller zips results back onto rows by position as well as by id.
        ids = [f"{n}{n}{n}{n}{n}{n}{n}{n}-1111-1111-1111-111111111111" for n in "456"]

        response = client.post(
            "/v1/anomaly/score",
            json={"observations": [observation(i, 12.0) for i in ids]},
        )

        assert [r["submission_id"] for r in response.json()["results"]] == ids

    def test_refuses_an_empty_batch(self, client: TestClient) -> None:
        # The schema requires at least one observation, and the caller already
        # returns early rather than sending nothing. Asserted so that a change
        # to either side has to be a deliberate one.
        response = client.post("/v1/anomaly/score", json={"observations": []})

        assert response.status_code == 422

    def test_refuses_a_batch_larger_than_it_will_serve(self, client: TestClient) -> None:
        # Bounded on purpose: an unbounded batch is a way to make one request
        # occupy the service indefinitely.
        response = client.post(
            "/v1/anomaly/score",
            json={
                "observations": [
                    observation("77777777-7777-7777-7777-777777777777", 12.0) for _ in range(1001)
                ]
            },
        )

        assert response.status_code == 422


class TestReporterBias:
    """Judging a reporter on their whole history rather than one price.

    This endpoint exposes a detector that existed for months with no caller, so
    the platform's only defence against coordinated manipulation was a module
    nothing ran.

    The fixtures below respect what the detector actually needs: a reporter is
    only profiled after fifteen observations, and at least three reporters must
    qualify before there is a population to be out of step with. Both floors
    exist so that one unlucky week cannot cost somebody their standing.
    """

    def population(self, ratios: dict[str, float], each: int = 20) -> list[dict[str, Any]]:
        """One reporter per ratio, each with enough history to be judged."""
        return [
            {"reporter_id": reporter, "price": 10.0 * ratio, "reference": 10.0}
            for reporter, ratio in ratios.items()
            for _ in range(each)
        ]

    def test_it_flags_a_reporter_who_is_consistently_low(self, client: TestClient) -> None:
        # The case the detector exists for: each individual price is plausible,
        # and only the pattern across a history is visible.
        records = self.population(
            {
                "honest-a": 0.98,
                "honest-b": 1.00,
                "honest-c": 1.01,
                "honest-d": 1.02,
                "suspect": 0.70,
            }
        )

        response = client.post("/v1/anomaly/reporter-bias", json={"records": records})

        assert response.status_code == 200
        assert response.json()["suspicious"] == ["suspect"]

    def test_it_explains_itself(self, client: TestClient) -> None:
        # A flag a human has to act on has to say why, in words. "Score -10.1"
        # is not something anybody can weigh against a person's work.
        records = self.population(
            {
                "honest-a": 0.98,
                "honest-b": 1.00,
                "honest-c": 1.01,
                "honest-d": 1.02,
                "suspect": 0.70,
            }
        )

        results = client.post("/v1/anomaly/reporter-bias", json={"records": records}).json()
        flagged = next(r for r in results["results"] if r["reporter_id"] == "suspect")

        assert "70%" in flagged["reason"]
        assert flagged["n_observations"] == 20
        assert flagged["modified_z"] < 0

    def test_it_leaves_ordinary_variation_alone(self, client: TestClient) -> None:
        # A false positive costs a real person their standing, so the bar is not
        # "differs from the median".
        records = self.population({"a": 0.98, "b": 1.00, "c": 1.01, "d": 1.02, "e": 0.99})

        response = client.post("/v1/anomaly/reporter-bias", json={"records": records})

        assert response.json()["suspicious"] == []

    def test_it_says_nothing_about_a_reporter_with_too_little_history(
        self, client: TestClient
    ) -> None:
        # Three observations is a bad week, not a pattern.
        records = self.population({"a": 0.5, "b": 1.0, "c": 1.0}, each=3)

        response = client.post("/v1/anomaly/reporter-bias", json={"records": records})

        assert response.json()["results"] == []
        assert response.json()["suspicious"] == []

    def test_it_needs_a_population_before_judging_anyone(self, client: TestClient) -> None:
        # Two reporters cannot tell you which of them is unusual.
        records = self.population({"a": 0.5, "b": 1.0})

        response = client.post("/v1/anomaly/reporter-bias", json={"records": records})

        assert response.json()["results"] == []

    def test_it_refuses_an_empty_request(self, client: TestClient) -> None:
        response = client.post("/v1/anomaly/reporter-bias", json={"records": []})

        assert response.status_code == 422
