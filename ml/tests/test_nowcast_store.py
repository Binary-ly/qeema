# SPDX-License-Identifier: Apache-2.0
"""Fitted models surviving a restart, and refusing to when they should not.

The models live in the service's memory. A restart — a deploy, a crash, an
operator running `docker compose up -d` — dropped every country back to a ±30%
fallback heuristic until the next scheduled training run, up to six hours later,
with figures that kept publishing and were quietly much cruder than the model
card describes.

Persisting them introduces a failure mode that not persisting does not have: a
model fitted on one set of features, loaded by code that now sends a different
set. Features are positional, so nothing raises — the model reads one feature
out of another's slot and returns a confident number. The compatibility tests
below are the ones that matter; the round-trip test is the easy half.
"""

from __future__ import annotations

import json
from pathlib import Path

from qeema_ml.nowcast.model import QUANTILES, NowcastFeatures, NowcastModel
from qeema_ml.nowcast.store import NowcastModelStore


def features(national_median: float = 10.0) -> NowcastFeatures:
    return NowcastFeatures(
        national_median=national_median,
        neighbour_median=national_median,
        neighbour_weighted=national_median,
        neighbour_count=3.0,
        nearest_neighbour_km=20.0,
        last_local_price=national_median,
        days_since_local=2.0,
        national_trend=1.0,
        fx_change_30d=1.0,
        location_price_level=1.0,
        day_of_week=3.0,
    )


def fitted_model(ratio: float = 1.4, rows: int = 300) -> NowcastModel:
    model = NowcastModel()
    model.fit(
        [features(10.0 + (i % 7)) for i in range(rows)],
        [ratio] * rows,
    )

    return model


class TestSurvivingARestart:
    def test_a_saved_model_comes_back(self, tmp_path: Path) -> None:
        store = NowcastModelStore(tmp_path)
        model = fitted_model()

        assert store.save("LY", model) is True

        # A restart, as far as this process is concerned.
        restored = store.load_all()

        assert "LY" in restored
        assert restored["LY"].is_trained is True
        assert restored["LY"].trained_rows == model.trained_rows

    def test_it_predicts_the_same_thing_afterwards(self, tmp_path: Path) -> None:
        store = NowcastModelStore(tmp_path)
        model = fitted_model()
        store.save("LY", model)

        before = model.predict(features())
        after = store.load_all()["LY"].predict(features())

        # Not merely loadable: the same model. A restored model that predicts
        # differently would be worse than no persistence, because nothing would
        # say the estimates had changed.
        assert before is not None and after is not None
        assert after.value == before.value
        assert after.lower == before.lower
        assert after.upper == before.upper

    def test_each_country_is_restored_separately(self, tmp_path: Path) -> None:
        store = NowcastModelStore(tmp_path)
        store.save("LY", fitted_model(ratio=2.0))
        store.save("VE", fitted_model(ratio=0.5))

        restored = store.load_all()

        assert set(restored) == {"LY", "VE"}

        ly = restored["LY"].predict(features())
        ve = restored["VE"].predict(features())

        assert ly is not None and ve is not None
        assert ly.value > ve.value

    def test_an_unfitted_model_is_not_written(self, tmp_path: Path) -> None:
        assert NowcastModelStore(tmp_path).save("LY", NowcastModel()) is False

    def test_an_empty_directory_restores_nothing(self, tmp_path: Path) -> None:
        assert NowcastModelStore(tmp_path / "nothing-here").load_all() == {}


class TestRefusingAnIncompatibleModel:
    def test_it_refuses_a_model_fitted_on_different_features(self, tmp_path: Path) -> None:
        # The failure persistence introduces. Features are positional, so a
        # model fitted before a feature was added or reordered would read one
        # feature out of another's slot and return a confident wrong number,
        # with nothing raising anywhere.
        store = NowcastModelStore(tmp_path)
        store.save("LY", fitted_model())

        manifest_path = tmp_path / "LY" / "manifest.json"
        manifest = json.loads(manifest_path.read_text())
        manifest["schema"] = ["a_feature_that_no_longer_exists", *manifest["schema"][1:]]
        manifest_path.write_text(json.dumps(manifest))

        assert store.load_all() == {}

    def test_it_refuses_a_model_fitted_at_different_quantiles(self, tmp_path: Path) -> None:
        # A model drawn at 0.1/0.9 published as an 80% band covers 74.6% of
        # true values. Loading one into code that claims the current band would
        # silently reinstate exactly that.
        store = NowcastModelStore(tmp_path)
        store.save("LY", fitted_model())

        manifest_path = tmp_path / "LY" / "manifest.json"
        manifest = json.loads(manifest_path.read_text())
        manifest["quantiles"] = [0.1, 0.5, 0.9]
        manifest_path.write_text(json.dumps(manifest))

        assert store.load_all() == {}

    def test_it_leaves_a_refused_model_on_disk(self, tmp_path: Path) -> None:
        # So an operator can see what was rejected rather than wondering where
        # it went.
        store = NowcastModelStore(tmp_path)
        store.save("LY", fitted_model())

        manifest_path = tmp_path / "LY" / "manifest.json"
        manifest = json.loads(manifest_path.read_text())
        manifest["quantiles"] = [0.1, 0.5, 0.9]
        manifest_path.write_text(json.dumps(manifest))

        store.load_all()

        assert manifest_path.is_file()

    def test_it_ignores_a_folder_with_no_manifest(self, tmp_path: Path) -> None:
        (tmp_path / "LY").mkdir()
        (tmp_path / "LY" / "q0.5.txt").write_text("not a model")

        assert NowcastModelStore(tmp_path).load_all() == {}

    def test_it_ignores_an_unreadable_manifest(self, tmp_path: Path) -> None:
        (tmp_path / "LY").mkdir()
        (tmp_path / "LY" / "manifest.json").write_text("{ this is not json")

        assert NowcastModelStore(tmp_path).load_all() == {}

    def test_it_ignores_a_manifest_pointing_at_missing_models(self, tmp_path: Path) -> None:
        (tmp_path / "LY").mkdir()
        (tmp_path / "LY" / "manifest.json").write_text(
            json.dumps(
                {
                    "schema": NowcastFeatures.names(),
                    "quantiles": list(QUANTILES),
                    "trained_rows": 500,
                }
            )
        )

        assert NowcastModelStore(tmp_path).load_all() == {}


class TestTheManifest:
    def test_it_records_what_the_model_was_fitted_on(self, tmp_path: Path) -> None:
        store = NowcastModelStore(tmp_path)
        model = fitted_model()
        store.save("LY", model)

        manifest = json.loads((tmp_path / "LY" / "manifest.json").read_text())

        assert manifest["schema"] == NowcastFeatures.names()
        assert manifest["quantiles"] == list(QUANTILES)
        assert manifest["trained_rows"] == model.trained_rows
        assert manifest["saved_at"]
