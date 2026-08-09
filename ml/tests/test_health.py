# SPDX-License-Identifier: Apache-2.0
"""Ops endpoint behaviour.

These back the compose healthchecks, so their exact status codes matter:
readiness must fail closed while weights are loading, or dependent services
start against a service that cannot serve inference.
"""

from __future__ import annotations

from fastapi.testclient import TestClient


def test_health_reports_ok_even_before_models_load(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "qeema-ml"
    assert body["version"]


def test_ready_returns_503_while_models_are_not_loaded(client: TestClient) -> None:
    response = client.get("/ready")

    assert response.status_code == 503
    body = response.json()
    assert body["status"] == "loading"
    assert body["models_loaded"] is False


def test_ready_returns_200_once_models_are_loaded(ready_client: TestClient) -> None:
    response = ready_client.get("/ready")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ready"
    assert body["models_loaded"] is True
    assert body["embedding_dim"] == 768


def test_model_info_reports_provenance(client: TestClient) -> None:
    response = client.get("/v1/model-info")

    assert response.status_code == 200
    body = response.json()
    assert body["embedding_model"] == "intfloat/multilingual-e5-base"
    assert body["embedding_dim"] == 768
    for key in ("matcher_version", "anomaly_version", "nowcast_version"):
        assert body[key]


def test_openapi_document_is_generated(client: TestClient) -> None:
    response = client.get("/openapi.json")

    assert response.status_code == 200
    spec = response.json()
    assert spec["info"]["title"] == "Qeema ML Service"
    assert spec["info"]["license"]["name"] == "Apache-2.0"
    assert "/health" in spec["paths"]
