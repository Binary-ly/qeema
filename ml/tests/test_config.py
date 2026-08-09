# SPDX-License-Identifier: Apache-2.0
"""Settings behaviour.

Configuration is the mechanism that keeps the service country-agnostic (C3) and
free of proprietary dependencies (C1), so it is tested rather than assumed.
"""

from __future__ import annotations

import pytest

from qeema_ml.config import Settings, get_settings


def test_defaults_use_open_weight_model() -> None:
    settings = Settings()

    assert settings.embedding_model == "intfloat/multilingual-e5-base"
    assert settings.embedding_dim == 768


def test_embedding_model_is_configurable(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("QEEMA_ML_EMBEDDING_MODEL", "intfloat/multilingual-e5-small")
    monkeypatch.setenv("QEEMA_ML_EMBEDDING_DIM", "384")

    settings = Settings()

    assert settings.embedding_model == "intfloat/multilingual-e5-small"
    assert settings.embedding_dim == 384


def test_e5_prefixes_are_configuration_not_hardcoded() -> None:
    settings = Settings()

    assert settings.embedding_query_prefix == "query: "
    assert settings.embedding_passage_prefix == "passage: "


def test_fusion_weights_are_normalised_to_sum_to_one() -> None:
    settings = Settings(match_lexical_weight=0.4, match_semantic_weight=0.6)

    lexical, semantic = settings.fusion_weights_normalised

    assert lexical == pytest.approx(0.4)
    assert semantic == pytest.approx(0.6)
    assert lexical + semantic == pytest.approx(1.0)


def test_fusion_weights_normalise_when_operator_weights_do_not_sum_to_one() -> None:
    # Each weight is individually valid but the pair sums to 0.8, which would
    # otherwise shift the fused score scale and quietly invalidate the
    # calibrated auto-resolve threshold.
    settings = Settings(match_lexical_weight=0.6, match_semantic_weight=0.2)

    lexical, semantic = settings.fusion_weights_normalised

    assert lexical == pytest.approx(0.75)
    assert semantic == pytest.approx(0.25)
    assert lexical + semantic == pytest.approx(1.0)


def test_fusion_weights_fall_back_when_both_weights_are_zero() -> None:
    settings = Settings(match_lexical_weight=0.0, match_semantic_weight=0.0)

    assert settings.fusion_weights_normalised == (0.5, 0.5)


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("match_lexical_weight", 1.5),
        ("match_auto_resolve_threshold", -0.1),
        ("match_top_k", 0),
        ("anomaly_hard_bound_factor", 0.5),
        ("anomaly_isolation_contamination", 0.9),
    ],
)
def test_out_of_range_settings_are_rejected(field: str, value: float) -> None:
    with pytest.raises(ValueError, match=field):
        Settings(**{field: value})


def test_get_settings_is_cached() -> None:
    get_settings.cache_clear()

    assert get_settings() is get_settings()
