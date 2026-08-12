# SPDX-License-Identifier: Apache-2.0
"""Runtime configuration for the Qeema ML service.

Every value is environment-driven so the service stays country-agnostic and
redeployable by a third party with no commercial accounts (constraints C1, C3).
"""

from __future__ import annotations

from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Service settings, read from the environment with a ``QEEMA_ML_`` prefix."""

    model_config = SettingsConfigDict(
        env_prefix="QEEMA_ML_",
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # --- service ---
    app_name: str = "qeema-ml"
    environment: str = "local"
    log_level: str = "INFO"

    # --- embedding model -------------------------------------------------
    # Open weights only. Configurable so an operator can swap in a smaller or
    # differently-licensed model without touching code.
    embedding_model: str = "intfloat/multilingual-e5-base"
    embedding_dim: int = 768
    embedding_batch_size: int = 32
    # e5 models are trained with asymmetric prefixes; omitting them measurably
    # degrades retrieval quality, so they are configuration, not string literals
    # scattered through the code.
    embedding_query_prefix: str = "query: "
    embedding_passage_prefix: str = "passage: "

    # Load weights eagerly at boot so readiness genuinely means ready. Disabled
    # in unit tests, which must not pull a 1.1 GB model.
    load_models_on_startup: bool = True

    # --- matching --------------------------------------------------------
    match_lexical_weight: float = Field(default=0.4, ge=0.0, le=1.0)
    match_semantic_weight: float = Field(default=0.6, ge=0.0, le=1.0)
    match_auto_resolve_threshold: float = Field(default=0.85, ge=0.0, le=1.0)
    match_review_threshold: float = Field(default=0.55, ge=0.0, le=1.0)
    match_top_k: int = Field(default=5, ge=1, le=50)

    # --- anomaly ---------------------------------------------------------
    anomaly_hard_bound_factor: float = Field(default=4.0, gt=1.0)
    anomaly_mad_threshold: float = Field(default=3.5, gt=0.0)
    anomaly_isolation_contamination: float = Field(default=0.05, gt=0.0, lt=0.5)

    # --- nowcasting ------------------------------------------------------
    # Where fitted nowcast models are kept between restarts. Without this the
    # models live only in memory, and every container restart drops each
    # country back to a fallback heuristic until the next training run.
    nowcast_model_dir: str = "/models"

    # The quantiles the band is drawn at are deliberately *not* configurable.
    # They are a calibration decision measured against a backtest — drawn at
    # 0.05/0.95 and published as 80% so the interval over-covers — and an
    # operator setting them back to 0.1/0.9 would silently restore an interval
    # that covers 74.6% of what it claims. A setting that existed here and was
    # read nowhere is worse still, which is what it was.

    # --- database (read-only access to the public schema) ----------------
    database_url: str = "postgresql://qeema:qeema@postgres:5432/qeema"

    @property
    def fusion_weights_normalised(self) -> tuple[float, float]:
        """Return lexical/semantic weights normalised to sum to 1.

        Operators tune these independently and can easily set a pair that does
        not sum to 1; normalising keeps the fused score on a stable scale so a
        calibrated confidence threshold stays meaningful.
        """
        total = self.match_lexical_weight + self.match_semantic_weight
        if total <= 0:
            return (0.5, 0.5)
        return (
            self.match_lexical_weight / total,
            self.match_semantic_weight / total,
        )


@lru_cache
def get_settings() -> Settings:
    """Return cached settings so the environment is parsed once per process."""
    return Settings()
