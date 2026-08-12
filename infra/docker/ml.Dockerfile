# SPDX-License-Identifier: Apache-2.0
# syntax=docker/dockerfile:1.7

# =============================================================================
# Qeema `ml` image — FastAPI on Python 3.11
#
# Two decisions here are load-bearing for the one-command demo (constraint C2):
#
#   1. torch comes from the CPU-only wheel index. The default linux wheel bundles
#      CUDA and is ~800 MB; this service does small-batch inference on CPU, where
#      that payload buys nothing.
#   2. Model weights are downloaded at BUILD time and baked into the image. A
#      first-boot download would make the demo slow and would fail outright in an
#      air-gapped review environment. The image is bigger; the demo always works.
# =============================================================================

FROM python:3.11-slim-bookworm AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HOME=/opt/models/hf \
    HF_HUB_OFFLINE=0 \
    TOKENIZERS_PARALLELISM=false

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        libgomp1 curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv


# ---------- stage: dependencies ----------
FROM base AS deps

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY ml/pyproject.toml ml/README.md ./
RUN mkdir -p src/qeema_ml && touch src/qeema_ml/__init__.py

# CPU-only torch first, so the dependency resolver cannot pull the CUDA build.
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install --index-url https://download.pytorch.org/whl/cpu "torch>=2.5"

RUN --mount=type=cache,target=/root/.cache/pip \
    pip install .


# ---------- stage: model weights ----------
FROM deps AS weights

ARG EMBEDDING_MODEL=intfloat/multilingual-e5-base

# Baking the weights in is what makes `docker compose up` work offline.
RUN python -c "\
from sentence_transformers import SentenceTransformer; \
SentenceTransformer('${EMBEDDING_MODEL}'); \
print('cached weights for ${EMBEDDING_MODEL}')"


# ---------- stage: runtime ----------
FROM base AS runtime

LABEL org.opencontainers.image.title="Qeema ML service" \
      org.opencontainers.image.description="Local-weights product matching, anomaly scoring and nowcasting for the Qeema open affordability index" \
      org.opencontainers.image.licenses="Apache-2.0"

# Once weights are baked in, forbid runtime downloads outright. If a code change
# ever asks for a model that was not baked, it must fail loudly at boot rather
# than quietly reaching out to the network in a deployment that has none.
ENV HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1

COPY --from=deps /usr/local/lib/python3.11/site-packages /usr/local/lib/python3.11/site-packages
COPY --from=deps /usr/local/bin /usr/local/bin
COPY --from=weights /opt/models/hf /opt/models/hf

COPY ml/pyproject.toml ml/README.md ./
COPY ml/src ./src
COPY ml/artifacts ./artifacts

# /models is where fitted nowcast models are persisted between restarts. It is
# created here, owned by the service user, so the named volume mounted over it
# inherits that ownership — a volume initialised against a root-owned path
# leaves the service unable to write, and the failure is a log line rather than
# an error, because a training run must not fail over a read-only disk.
RUN useradd --system --uid 10001 --create-home qeema \
    && mkdir -p /models \
    && chown -R qeema:qeema /srv /opt/models /models
USER qeema

ENV PYTHONPATH=/srv/src

EXPOSE 8000

# Readiness, not liveness: /ready returns 503 until weights finish loading, so
# dependent services do not start against a service that cannot infer yet.
HEALTHCHECK --interval=10s --timeout=5s --start-period=120s --retries=18 \
    CMD curl -fsS http://127.0.0.1:8000/ready || exit 1

CMD ["uvicorn", "qeema_ml.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
