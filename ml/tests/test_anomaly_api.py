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
