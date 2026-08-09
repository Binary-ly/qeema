-- SPDX-License-Identifier: Apache-2.0
-- Runs once on first initialisation of the Postgres data directory.
--
-- pgvector backs semantic product matching; pg_trgm backs lexical matching.
-- Both are required before the first migration runs, which is why this lives in
-- docker-entrypoint-initdb.d rather than in a Laravel migration.

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;

-- Ground-truth labels for the synthetic generator live in their own schema so a
-- label can never leak into a published API response (PLAN.md D-06).
CREATE SCHEMA IF NOT EXISTS qeema_eval;

COMMENT ON SCHEMA qeema_eval IS
    'Synthetic ground-truth labels for ML evaluation. Never exposed by the public API.';
