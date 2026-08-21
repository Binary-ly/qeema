# SPDX-License-Identifier: Apache-2.0
#
# Qeema developer and operator tasks.
# `make demo` is the one command a reviewer needs.

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE ?= docker compose
API_DIR := api
ML_DIR  := ml
PY      := $(ML_DIR)/.venv/bin/python
APP_URL ?= http://localhost:8080

.PHONY: help
help: ## Show available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------- demo -----

.PHONY: demo
demo: ## Build, start and wait for the full seeded stack (the reviewer path)
	$(COMPOSE) build
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory wait
	@echo ""
	@echo "  Qeema is up."
	@echo "    Dashboard   $(APP_URL)"
	@echo "    Public API  $(APP_URL)/api/v1/health"
	@echo "    API docs    $(APP_URL)/docs"
	@echo "    Admin       $(APP_URL)/admin"
	@echo ""

.PHONY: wait
wait: ## Block until the stack reports healthy
	@echo "waiting for services to become healthy..."
	@for i in $$(seq 1 120); do \
		if curl -fsS $(APP_URL)/api/v1/health >/dev/null 2>&1; then \
			echo "app is healthy"; exit 0; \
		fi; \
		sleep 5; \
	done; \
	echo "timed out waiting for the app; recent logs:"; \
	$(COMPOSE) logs --tail=60 app ml; \
	exit 1

.PHONY: up down logs ps restart
up: ## Start the stack in the background
	$(COMPOSE) up -d
down: ## Stop the stack (data volumes are preserved)
	$(COMPOSE) down
logs: ## Follow logs for all services
	$(COMPOSE) logs -f
ps: ## Show service status
	$(COMPOSE) ps
restart: ## Restart application containers
	$(COMPOSE) restart app worker ml

.PHONY: nuke
nuke: ## Stop the stack and DELETE all data volumes
	$(COMPOSE) down -v --remove-orphans

# ---------------------------------------------------------------- tests ----

.PHONY: test
test: test-php test-ml ## Run both test suites with coverage gates

.PHONY: test-php
test-php: ## Run the PHP suite with the 80% coverage gate
	cd $(API_DIR) && ./vendor/bin/pest --coverage --min=80

.PHONY: test-ml
test-ml: ## Run the Python suite with the 80% coverage gate
	cd $(ML_DIR) && .venv/bin/python -m pytest --cov --cov-report=term-missing

.PHONY: test-e2e
test-e2e: ## Run Playwright end-to-end tests against the running stack
	cd e2e && npm install --silent && npx playwright install chromium --with-deps 2>/dev/null || true
	cd e2e && npx playwright test

.PHONY: lint
lint: ## Lint and statically analyse both services
	# --memory-limit: analysing a Laravel app of this size needs more than PHP's
	# 256M default, and exceeding it crashes the worker with a message about
	# php.ini rather than reporting any analysis. CI sets memory_limit=-1 so it
	# never saw this; a fresh clone on a stock PHP does, every time.
	cd $(API_DIR) && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	cd $(ML_DIR) && .venv/bin/ruff check src tests && .venv/bin/ruff format --check src tests
	# mypy is a CI gate and was missing here, so `make verify` could pass on a
	# type error the pipeline would then reject. src only, matching CI: the
	# tests are not annotated to the same standard.
	cd $(ML_DIR) && .venv/bin/mypy src

.PHONY: fix
fix: ## Auto-fix formatting in both services
	cd $(API_DIR) && ./vendor/bin/pint
	cd $(ML_DIR) && .venv/bin/ruff check --fix src tests && .venv/bin/ruff format src tests

# ------------------------------------------------------------ compliance ---

.PHONY: licenses
licenses: ## Regenerate the dependency licence inventory (constraint C1)
	@mkdir -p docs
	@bash infra/scripts/licenses.sh > docs/LICENSES.md
	@echo "wrote docs/LICENSES.md"

.PHONY: check-country-agnostic
check-country-agnostic: ## Fail if country-specific literals leaked into code (constraint C3)
	@bash infra/scripts/check-country-agnostic.sh

.PHONY: check-workflows
check-workflows: ## Fail on duplicate keys that would make a CI workflow invalid
	@bash infra/scripts/check-workflows.sh

.PHONY: check-openapi
check-openapi: ## Fail if the published spec has drifted from the source it is generated from
	cd $(API_DIR) && php artisan qeema:openapi --check

# Every CI gate that does not need Docker. The two exceptions are deliberate and
# worth knowing: the compose job (image build, stack boot, Playwright) is
# `make test-e2e` against a running stack, and CI additionally greps the tree for
# secret-shaped values — a check that lives only in the workflow file.
#
# The help text used to read "Everything CI runs", which was false: mypy and the
# OpenAPI drift check were both gates and neither was reachable from here, so a
# green verify could still fail the pipeline.
.PHONY: verify
verify: lint test check-country-agnostic check-workflows check-openapi ## Every CI gate except the Docker build and e2e

# ----------------------------------------------------------------- data ----

.PHONY: seed
seed: ## Re-run bootstrap (migrate + seed) inside the app container
	$(COMPOSE) exec app php artisan qeema:bootstrap --force

.PHONY: reseed
reseed: ## Drop the schema and rebuild it with fresh demo data (destructive)
	$(COMPOSE) exec app php artisan qeema:bootstrap --force --fresh

.PHONY: shell
shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

.PHONY: psql
psql: ## Open psql against the running database
	$(COMPOSE) exec postgres psql -U $${POSTGRES_USER:-qeema} -d $${POSTGRES_DB:-qeema}
