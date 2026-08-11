<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Qeema platform configuration
|--------------------------------------------------------------------------
|
| Platform-level settings only. Country facts — currency, locations, basket
| composition, locales, FX source — belong in countries/*.yaml and are loaded
| into the database, never read from here (constraint C3).
|
*/

return [

    'version' => env('QEEMA_VERSION', '0.1.0'),

    /*
    | Directory holding country configuration files. Each *.yaml in here is a
    | self-contained country definition that can be seeded independently.
    */
    'countries_path' => env('QEEMA_COUNTRIES_PATH', base_path('../countries')),

    /*
    | Which country configs to seed on boot. Comma-separated ISO-3166-1 alpha-2
    | codes, or '*' for every file present.
    */
    'seed_countries' => env('QEEMA_SEED_COUNTRIES', '*'),

    /*
    | The ML service. Reached only over HTTP; the app never imports an ML
    | library directly.
    */
    'ml' => [
        'base_url' => env('QEEMA_ML_URL', 'http://ml:8000'),
        'timeout' => (float) env('QEEMA_ML_TIMEOUT', 10.0),
        'connect_timeout' => (float) env('QEEMA_ML_CONNECT_TIMEOUT', 2.0),
        'retries' => (int) env('QEEMA_ML_RETRIES', 2),
        'retry_delay_ms' => (int) env('QEEMA_ML_RETRY_DELAY_MS', 200),

        /*
        | Circuit breaker. When the ML service fails this many times in a row,
        | stop calling it for `cooldown_seconds` and degrade: submissions queue
        | for human review instead of auto-resolving, and the index is computed
        | from observed data only with the reduced coverage reported honestly.
        */
        'circuit_breaker' => [
            'failure_threshold' => (int) env('QEEMA_ML_CB_FAILURES', 5),
            'cooldown_seconds' => (int) env('QEEMA_ML_CB_COOLDOWN', 60),
        ],
    ],

    /*
    | Public API. Read routes are unauthenticated by design (C6); the only
    | protection on them is per-IP rate limiting.
    */
    'api' => [
        'rate_limit_per_minute' => (int) env('QEEMA_API_RATE_LIMIT', 120),

        /*
        | Write limit, applied per reporter device. Generous enough that an
        | offline queue holding a day's observations flushes in one go, tight
        | enough that a runaway retry loop or a manipulation attempt is bounded.
        */
        'submission_rate_limit_per_minute' => (int) env('QEEMA_SUBMISSION_RATE_LIMIT', 60),
        'max_page_size' => (int) env('QEEMA_API_MAX_PAGE_SIZE', 500),

        // Far tighter than ordinary reads: an export streams the whole
        // published history, so a retry loop against it costs vastly more than
        // one against a single snapshot.
        'export_rate_limit_per_minute' => (int) env('QEEMA_EXPORT_RATE_LIMIT', 5),
        'export_chunk_size' => (int) env('QEEMA_API_EXPORT_CHUNK', 1000),
    ],

    /*
    | The ingestion pipeline: how an inbound submission becomes a published
    | figure without anyone typing a command.
    |
    | Two queues rather than one. A partner spreadsheet with fifty thousand rows
    | and a reporter standing in a market with one price are both legitimate
    | work, but only one of them is waiting; putting them on the same queue lets
    | the import decide how long the reporter waits.
    */
    'pipeline' => [
        'queue_live' => env('QEEMA_PIPELINE_QUEUE_LIVE', 'pipeline-live'),
        'queue_bulk' => env('QEEMA_PIPELINE_QUEUE_BULK', 'pipeline-bulk'),

        /*
        | Attempts before a submission the pipeline cannot process is handed to
        | a human with the error attached. The budget is counted on the
        | submission row, not on the queue message, so it survives a job being
        | lost and re-adopted by the sweeper — five attempts means five, however
        | many times the work was dispatched.
        */
        'max_attempts' => (int) env('QEEMA_PIPELINE_MAX_ATTEMPTS', 5),

        /*
        | The reconciler. Dispatch-on-write is the fast path; this is the
        | guarantee. Anything still pending after this many seconds is adopted
        | regardless of how it was written, which covers bulk inserts that fire
        | no model events and jobs lost to a killed container.
        */
        'sweep_age_seconds' => (int) env('QEEMA_PIPELINE_SWEEP_AGE', 120),
        'sweep_limit' => (int) env('QEEMA_PIPELINE_SWEEP_LIMIT', 500),

        /*
        | How far back the sweeper looks for observations nobody screened.
        |
        | Bounded on purpose. The sweeper exists to catch work the fast path
        | missed, not to retro-screen history: a seeded deployment holds tens of
        | thousands of observations that were written wholesale rather than
        | through the pipeline, and an unbounded sweep would re-dispatch them
        | every minute forever. An observation still unscreened after this
        | window is a signal that something is wrong, not a job to retry.
        */
        'sweep_scoring_window_hours' => (int) env('QEEMA_PIPELINE_SCORE_WINDOW_HOURS', 24),

        /*
        | How late the pipeline may be before it is reported as degraded.
        |
        | Generous relative to the one-minute cadence of the sweeper and the
        | drain: a single slow tick is ordinary, and an alert that fires on
        | ordinary conditions is an alert people learn to close.
        */
        'alert_minutes' => (int) env('QEEMA_PIPELINE_ALERT_MINUTES', 15),

        /*
        | How long a submission may wait for a human before the queue is
        | reported as unworked. Size is not the signal — a large queue being
        | drained is healthy — age is.
        */
        'review_alert_days' => (int) env('QEEMA_REVIEW_ALERT_DAYS', 7),
    ],

    /*
    | Exchange rates.
    |
    | The platform ships with no source for any currency: every provider is
    | selected per country in countries/*.yaml, and the default everywhere is
    | manual entry. That is constraint C1 in practice — a rate feed worth
    | trusting for these currencies is behind an API key, and depending on one
    | would give every deployment an account to create and a secret to keep.
    */
    'fx' => [
        'fetch_enabled' => filter_var(env('QEEMA_FX_FETCH_ENABLED', true), FILTER_VALIDATE_BOOL),
        'http_timeout' => (float) env('QEEMA_FX_HTTP_TIMEOUT', 10.0),
    ],

    /*
    | Index publication.
    */
    'index' => [
        'drain_limit' => (int) env('QEEMA_INDEX_DRAIN_LIMIT', 500),

        /*
        | A snapshot is not recomputed until it has been stale this long.
        |
        | An observation marks its snapshots stale the instant it is created,
        | but anomaly screening happens a moment later in the next job. Without
        | a grace window a recompute landing in that gap publishes a figure
        | containing a price nobody has screened, and corrects it seconds later.
        | Briefly wrong in public is the one thing this platform must not be.
        */
        'publish_grace_seconds' => (int) env('QEEMA_INDEX_PUBLISH_GRACE', 60),

        /*
        | How many days back the roll-forward looks for snapshots that were
        | never created — a location with no reports for two days still needs a
        | published figure once one arrives.
        */
        'backfill_days' => (int) env('QEEMA_INDEX_BACKFILL_DAYS', 3),
    ],

    /*
    | Initial admin account, created on first boot so the panel is reachable
    | without exec-ing into a container.
    |
    | Read through config rather than env() at the point of use: the container
    | entrypoint runs `config:cache` before seeding, and env() is unreliable
    | once configuration is cached.
    */
    'admin' => [
        'email' => env('QEEMA_ADMIN_EMAIL', 'admin@qeema.local'),
        'password' => env('QEEMA_ADMIN_PASSWORD', 'qeema-demo'),
    ],

    /*
    | Seeding. `demo` controls whether the synthetic generator runs on boot so
    | that `docker compose up` yields a populated, demonstrable system (C2).
    */
    'seed' => [
        'demo' => filter_var(env('QEEMA_SEED_DEMO', true), FILTER_VALIDATE_BOOL),
        'demo_months' => (int) env('QEEMA_SEED_DEMO_MONTHS', 6),
        'demo_seed' => (int) env('QEEMA_SEED_RANDOM_SEED', 20260101),
    ],

];
