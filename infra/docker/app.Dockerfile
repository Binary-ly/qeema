# SPDX-License-Identifier: Apache-2.0
# syntax=docker/dockerfile:1.7

# =============================================================================
# Qeema `app` / `worker` image — Laravel 13 on PHP 8.4
#
# One image serves both the web container and the queue worker; they differ only
# by entrypoint command. Frontend assets are compiled at BUILD time, never at
# runtime, so `docker compose up` on a clean machine has nothing left to do
# (constraint C2).
# =============================================================================

# ---------- stage 1: frontend assets ----------
FROM node:22-bookworm-slim AS assets

WORKDIR /build

COPY api/package.json api/package-lock.json* ./
RUN --mount=type=cache,target=/root/.npm \
    if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY api/ ./
RUN npm run build


# ---------- stage 2: PHP dependencies ----------
FROM php:8.4-fpm-bookworm AS vendor

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    # pcntl is required by laravel/horizon, which uses signals to supervise its
    # worker processes. It is not enabled in the official PHP images.
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl bcmath pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY api/composer.json api/composer.lock ./

# --no-scripts because artisan is not present yet; scripts run after the full
# source is copied. --no-dev keeps development tooling out of the runtime image.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader


# ---------- stage 3: runtime ----------
FROM php:8.4-fpm-bookworm AS runtime

LABEL org.opencontainers.image.title="Qeema app" \
      org.opencontainers.image.description="Public API, admin, dashboard and reporter PWA for the Qeema open affordability index" \
      org.opencontainers.image.licenses="Apache-2.0"

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor postgresql-client \
        libpq5 libzip4 libicu72 \
        libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl bcmath opcache pcntl \
    && pecl install redis pcov \
    && docker-php-ext-enable redis \
    && apt-get purge -y libpq-dev libzip-dev libicu-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# pcov ships enabled-but-inert: it costs nothing until -d pcov.enabled=1 is
# passed, which lets the same image run the coverage-gated test suite in CI
# without building a separate test image.
RUN { \
      echo "pcov.enabled=0"; \
      echo "pcov.directory=/app/app"; \
    } > /usr/local/etc/php/conf.d/pcov.ini

COPY infra/docker/php/php.ini /usr/local/etc/php/conf.d/99-qeema.ini
COPY infra/docker/nginx/default.conf /etc/nginx/sites-available/default
COPY infra/docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY api/ ./
COPY --from=assets /build/public/build ./public/build

COPY infra/docker/entrypoint-app.sh /usr/local/bin/entrypoint-app
COPY infra/docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker
COPY infra/docker/entrypoint-scheduler.sh /usr/local/bin/entrypoint-scheduler
RUN chmod +x /usr/local/bin/entrypoint-app /usr/local/bin/entrypoint-worker \
    /usr/local/bin/entrypoint-scheduler

RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=90s --retries=12 \
    CMD curl -fsS http://127.0.0.1:8080/api/v1/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint-app"]
