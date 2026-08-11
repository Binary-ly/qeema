<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\PipelineHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service health for the public API.
 *
 * This backs the compose healthcheck for the `app` container, so it reports the
 * state of the dependencies the API actually needs. It reports degraded rather
 * than failing outright when the ML service is unreachable: the platform is
 * designed to keep serving observed data when inference is unavailable.
 */
final class HealthController extends Controller
{
    public function show(PipelineHealth $pipeline): JsonResponse
    {
        $database = $this->checkDatabase();

        // Only the database is load-bearing for serving the published index.
        // Redis and the ML service degrade the system without breaking it.
        $healthy = $database['ok'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'service' => config('app.name'),
            'version' => config('qeema.version'),
            'checks' => [
                'database' => $database,
            ],
            'pipeline' => $this->pipelineBlock($pipeline),
        ], $healthy ? 200 : 503);
    }

    /**
     * Whether the platform is still publishing, in public.
     *
     * States and ages, never counts. A consumer building on this data has a
     * legitimate interest in knowing the index has stopped moving — publishing
     * the *size* of the review backlog would additionally tell somebody probing
     * for a manipulation window how thin the screening currently is.
     *
     * Deliberately does not affect the HTTP status. This endpoint backs the
     * container healthcheck, and a pipeline that is merely behind must not get
     * the web container restarted underneath it.
     *
     * @return array<string, mixed>
     */
    private function pipelineBlock(PipelineHealth $pipeline): array
    {
        try {
            $checks = $pipeline->cachedChecks();
        } catch (Throwable) {
            // Health reporting must never be the thing that takes health
            // reporting down.
            return ['status' => 'unknown'];
        }

        $block = ['status' => $pipeline->overallStatus($checks)];

        foreach ($checks as $check) {
            $block[$check->key] = $check->toPublicArray();
        }

        return $block;
    }

    /**
     * @return array{ok: bool, extensions?: array<string, bool>, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            // Assert the extensions are present, not merely that a connection
            // opens. A Postgres without pgvector or pg_trgm will accept
            // connections and then fail every match query at runtime.
            $installed = DB::table('pg_extension')->pluck('extname')->all();

            $extensions = [
                'vector' => in_array('vector', $installed, true),
                'pg_trgm' => in_array('pg_trgm', $installed, true),
            ];

            return [
                'ok' => ! in_array(false, $extensions, true),
                'extensions' => $extensions,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
