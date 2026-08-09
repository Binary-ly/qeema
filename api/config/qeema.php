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
        'max_page_size' => (int) env('QEEMA_API_MAX_PAGE_SIZE', 500),
        'export_chunk_size' => (int) env('QEEMA_API_EXPORT_CHUNK', 1000),
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
