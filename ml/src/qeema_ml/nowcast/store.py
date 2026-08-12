# SPDX-License-Identifier: Apache-2.0
"""Keeping fitted models across a restart.

The models live in this process's memory. Restart the container — a deploy, a
crash, an operator running `docker compose up -d` — and every country drops back
to a ±30% fallback heuristic until the next scheduled training run, up to six
hours later. The figures keep publishing, keep their intervals, stay labelled
imputed, and are quietly much cruder than the model card describes.

So the fitted models are written to disk and read back at startup.

**The manifest is the point, not the model files.** Persistence introduces a
failure mode that not persisting does not have: a model fitted against one set
of features, loaded by code that now sends a different set. The features are
positional, so nothing would raise — the model would simply read `fx_change_30d`
out of the slot where `national_trend` now sits and return a confident number.

Every saved model therefore carries the ordered feature names, the quantiles it
was fitted at, and the coverage those were meant to deliver. A model whose
manifest disagrees with the running code is refused and left on disk, so an
operator can see what was rejected rather than wondering where it went.
"""

from __future__ import annotations

import json
import logging
from datetime import UTC, datetime
from pathlib import Path

from qeema_ml.nowcast.model import (
    NOMINAL_COVERAGE,
    QUANTILES,
    NowcastFeatures,
    NowcastModel,
)

try:  # pragma: no cover - dependency is declared
    import lightgbm as lgb
except ImportError:  # pragma: no cover
    lgb = None  # type: ignore[assignment]

logger = logging.getLogger(__name__)

MANIFEST = "manifest.json"


class NowcastModelStore:
    """Reads and writes fitted models under one directory, one folder per country."""

    def __init__(self, directory: str | Path) -> None:
        self.directory = Path(directory)

    def save(self, country: str, model: NowcastModel) -> bool:
        """Write a fitted model, or report that it could not be written.

        Never raises. A model that cannot be persisted is a slower recovery
        after the next restart, which is a great deal better than a training run
        that fails because a disk was read-only.
        """
        if lgb is None or not model.is_trained:
            return False

        try:
            target = self.directory / country.upper()
            target.mkdir(parents=True, exist_ok=True)

            for quantile, booster in model.boosters().items():
                booster.save_model(str(target / f"q{quantile}.txt"))

            (target / MANIFEST).write_text(
                json.dumps(
                    {
                        "schema": NowcastFeatures.names(),
                        "quantiles": list(QUANTILES),
                        "nominal_coverage": NOMINAL_COVERAGE,
                        "trained_rows": model.trained_rows,
                        "saved_at": datetime.now(UTC).isoformat(),
                    },
                    indent=2,
                ),
                encoding="utf-8",
            )
        except OSError as error:
            logger.warning("Could not persist the %s nowcast model: %s", country, error)
            return False

        logger.info("Persisted the %s nowcast model (%d rows).", country, model.trained_rows)

        return True

    def load_all(self) -> dict[str, NowcastModel]:
        """Every persisted model the running code can still serve."""
        if lgb is None or not self.directory.is_dir():
            return {}

        models: dict[str, NowcastModel] = {}

        for folder in sorted(self.directory.iterdir()):
            if not folder.is_dir():
                continue

            model = self._load(folder)

            if model is not None:
                models[folder.name.upper()] = model

        return models

    def _load(self, folder: Path) -> NowcastModel | None:
        manifest_path = folder / MANIFEST

        if not manifest_path.is_file():
            return None

        try:
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as error:
            logger.warning("Ignoring an unreadable model manifest in %s: %s", folder, error)
            return None

        if not self._is_compatible(folder, manifest):
            return None

        try:
            boosters = {
                quantile: lgb.Booster(model_file=str(folder / f"q{quantile}.txt"))
                for quantile in QUANTILES
            }
        except (OSError, lgb.basic.LightGBMError) as error:
            logger.warning("Ignoring an unreadable model in %s: %s", folder, error)
            return None

        logger.info("Restored the %s nowcast model from disk.", folder.name)

        return NowcastModel.from_boosters(boosters, int(manifest.get("trained_rows", 0)))

    def _is_compatible(self, folder: Path, manifest: dict) -> bool:
        """Would this model be served the features it was fitted on?

        Refusing is the safe answer, and the alternative is the reason this
        check exists: features are positional, so a mismatch does not raise. The
        model reads one feature out of another's slot and returns a number that
        looks exactly like every other number it returns.
        """
        if manifest.get("schema") != NowcastFeatures.names():
            logger.warning(
                "Refusing the model in %s: it was fitted on a different feature set.",
                folder,
            )
            return False

        if manifest.get("quantiles") != list(QUANTILES):
            logger.warning(
                "Refusing the model in %s: it was fitted at quantiles %s, not %s.",
                folder,
                manifest.get("quantiles"),
                list(QUANTILES),
            )
            return False

        return True
