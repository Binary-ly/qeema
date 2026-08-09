# SPDX-License-Identifier: Apache-2.0
"""Model registry boot behaviour.

This is the path compose readiness depends on. It is tested with a stub rather
than real weights so the suite stays offline and fast.
"""

from __future__ import annotations

import sys
import types
from typing import Any

import pytest

from qeema_ml.config import Settings
from qeema_ml.main import ModelRegistry


@pytest.fixture
def stub_sentence_transformers(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    """Install a stub sentence_transformers module and record what it loads."""
    loaded: list[str] = []

    class StubSentenceTransformer:
        def __init__(self, name: str, *args: Any, **kwargs: Any) -> None:
            loaded.append(name)
            self.name = name

    module = types.ModuleType("sentence_transformers")
    module.SentenceTransformer = StubSentenceTransformer  # type: ignore[attr-defined]
    monkeypatch.setitem(sys.modules, "sentence_transformers", module)
    return loaded


def test_registry_starts_unloaded() -> None:
    registry = ModelRegistry()

    assert registry.loaded is False
    assert registry.embedder is None


def test_load_uses_the_configured_model_name(stub_sentence_transformers: list[str]) -> None:
    registry = ModelRegistry()
    settings = Settings(embedding_model="intfloat/multilingual-e5-small")

    registry.load(settings)

    assert stub_sentence_transformers == ["intfloat/multilingual-e5-small"]
    assert registry.loaded is True
    assert registry.embedder is not None


def test_reset_clears_loaded_state(stub_sentence_transformers: list[str]) -> None:
    registry = ModelRegistry()
    registry.load(Settings())

    registry.reset()

    assert registry.loaded is False
    assert registry.embedder is None


def test_app_loads_models_at_startup_when_enabled(
    monkeypatch: pytest.MonkeyPatch,
    stub_sentence_transformers: list[str],
) -> None:
    """The lifespan hook must actually load, or /ready would never turn green."""
    from fastapi.testclient import TestClient

    from qeema_ml.config import get_settings
    from qeema_ml.main import create_app, registry

    monkeypatch.setenv("QEEMA_ML_LOAD_MODELS_ON_STARTUP", "true")
    get_settings.cache_clear()
    registry.reset()

    try:
        with TestClient(create_app()) as client:
            assert stub_sentence_transformers == ["intfloat/multilingual-e5-base"]
            assert client.get("/ready").status_code == 200
    finally:
        registry.reset()
        get_settings.cache_clear()
