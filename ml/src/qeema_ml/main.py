# SPDX-License-Identifier: Apache-2.0
"""FastAPI application for the Qeema ML service.

The Laravel application never imports an ML library; every inference call
crosses this HTTP boundary. That keeps the ML service independently deployable
and lets the PHP test suite run without loading model weights.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import TYPE_CHECKING, Any

from fastapi import FastAPI
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from qeema_ml import __version__
from qeema_ml.api.anomaly import router as anomaly_router
from qeema_ml.api.matching import router as matching_router
from qeema_ml.api.nowcast import router as nowcast_router
from qeema_ml.config import Settings, get_settings

if TYPE_CHECKING:
    from collections.abc import AsyncIterator

logger = logging.getLogger(__name__)


class HealthResponse(BaseModel):
    """Liveness payload. Answers 'is the process up', nothing more."""

    status: str = Field(examples=["ok"])
    service: str
    version: str


class ReadyResponse(BaseModel):
    """Readiness payload. Answers 'can this process serve inference'."""

    status: str = Field(examples=["ready", "loading"])
    models_loaded: bool
    embedding_model: str
    embedding_dim: int


class ModelInfoResponse(BaseModel):
    """Describes the loaded models so callers can record provenance."""

    embedding_model: str
    embedding_dim: int
    matcher_version: str
    anomaly_version: str
    nowcast_version: str


class ModelRegistry:
    """Holds loaded models for the process lifetime.

    Kept deliberately small: readiness is a property of this object, so the
    healthcheck cannot report ready while weights are still loading.
    """

    def __init__(self) -> None:
        self.embedder: Any | None = None
        self.loaded: bool = False

    def load(self, settings: Settings) -> None:
        """Load open-weight models from local disk.

        Imported lazily so that unit tests, which never call this, do not pay
        the import cost of torch and sentence-transformers.
        """
        from sentence_transformers import SentenceTransformer

        logger.info("loading embedding model %s", settings.embedding_model)
        self.embedder = SentenceTransformer(settings.embedding_model)
        self.loaded = True
        logger.info("embedding model ready")

    def reset(self) -> None:
        self.embedder = None
        self.loaded = False


registry = ModelRegistry()


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    """Load models at boot when configured to do so."""
    settings = get_settings()
    if settings.load_models_on_startup:
        registry.load(settings)
    else:
        logger.warning("model loading disabled; inference endpoints will not be ready")
    yield
    registry.reset()


def create_app() -> FastAPI:
    """Build the FastAPI application.

    A factory rather than a module-level singleton so tests can construct an
    app with model loading disabled.
    """
    settings = get_settings()
    app = FastAPI(
        title="Qeema ML Service",
        description=(
            "Product matching, anomaly scoring and price nowcasting for the "
            "Qeema open affordability index. All models run locally from open "
            "weights; no external inference API is used."
        ),
        version=__version__,
        lifespan=lifespan,
        license_info={"name": "Apache-2.0", "url": "https://www.apache.org/licenses/LICENSE-2.0"},
    )

    app.include_router(matching_router)
    app.include_router(anomaly_router)
    app.include_router(nowcast_router)

    @app.get("/health", response_model=HealthResponse, tags=["ops"])
    def health() -> HealthResponse:
        """Liveness probe. Returns ok as long as the process is serving."""
        return HealthResponse(status="ok", service=settings.app_name, version=__version__)

    @app.get("/ready", response_model=ReadyResponse, tags=["ops"])
    def ready() -> JSONResponse:
        """Readiness probe.

        Returns 503 until weights are loaded so that compose's
        ``service_healthy`` gate does not let dependent services start early.
        """
        payload = ReadyResponse(
            status="ready" if registry.loaded else "loading",
            models_loaded=registry.loaded,
            embedding_model=settings.embedding_model,
            embedding_dim=settings.embedding_dim,
        )
        return JSONResponse(
            status_code=200 if registry.loaded else 503,
            content=payload.model_dump(),
        )

    @app.get("/v1/model-info", response_model=ModelInfoResponse, tags=["ops"])
    def model_info() -> ModelInfoResponse:
        """Report model provenance, recorded against every resolution."""
        return ModelInfoResponse(
            embedding_model=settings.embedding_model,
            embedding_dim=settings.embedding_dim,
            matcher_version=f"matcher-{__version__}",
            anomaly_version=f"anomaly-{__version__}",
            nowcast_version=f"nowcast-{__version__}",
        )

    return app


app = create_app()
