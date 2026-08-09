# SPDX-License-Identifier: Apache-2.0
"""Shared pytest fixtures.

Unit tests never load real model weights: a 1.1 GB download per test run would
make the suite unusable and would pull from the network in CI. Tests that
genuinely exercise the weights are marked ``slow`` and excluded by default.
"""

from __future__ import annotations

import os
from collections.abc import Iterator

import pytest
from fastapi.testclient import TestClient

os.environ.setdefault("QEEMA_ML_LOAD_MODELS_ON_STARTUP", "false")

from qeema_ml.config import Settings, get_settings
from qeema_ml.main import create_app, registry


@pytest.fixture(autouse=True)
def _clear_settings_cache() -> Iterator[None]:
    """Stop settings leaking between tests that patch the environment."""
    get_settings.cache_clear()
    yield
    get_settings.cache_clear()


@pytest.fixture
def settings() -> Settings:
    return get_settings()


@pytest.fixture
def client() -> Iterator[TestClient]:
    """App with model loading disabled, so /ready reports 503."""
    registry.reset()
    with TestClient(create_app()) as c:
        yield c
    registry.reset()


@pytest.fixture
def ready_client() -> Iterator[TestClient]:
    """App whose registry is marked loaded, without touching real weights."""
    registry.reset()
    with TestClient(create_app()) as c:
        registry.loaded = True
        yield c
    registry.reset()
