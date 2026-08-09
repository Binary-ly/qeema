<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('reports ok when the database and required extensions are present', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.database.extensions.vector', true)
        ->assertJsonPath('checks.database.extensions.pg_trgm', true);
});

it('exposes the platform version so a deployment can be identified', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonStructure(['status', 'service', 'version', 'checks']);
});

it('is reachable without authentication because the data is the product', function () {
    // Guards constraint C6: a regression that adds auth middleware to the
    // public group would break the platform's entire reason for existing.
    $this->getJson('/api/v1/health')->assertOk();
});

it('reports unhealthy with 503 when the database is unreachable', function () {
    // A healthcheck that returns 200 while the database is down would let
    // compose declare the stack ready when it cannot serve a single request.
    DB::shouldReceive('table')
        ->with('pg_extension')
        ->andThrow(new RuntimeException('could not connect to server'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.database.ok', false)
        ->assertJsonPath('checks.database.error', 'could not connect to server');
});
