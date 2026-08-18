<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\OpenApi;

use OpenApi\Attributes as OA;

/** Endpoint descriptions for the public API. */
final class Paths
{
    #[OA\Get(
        path: '/countries',
        tags: ['reference'],
        summary: 'Countries this deployment publishes',
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function countries(): void {}

    #[OA\Get(
        path: '/countries/{countryCode}/basket',
        tags: ['reference'],
        summary: 'The basket being costed, with its weights',
        description: 'Weights are a judgement rather than a fact, so they are published: a consumer cannot disagree with the basket composition without seeing it.',
        parameters: [new OA\Parameter(name: 'countryCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'LY'))],
        responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 404, description: 'Unknown country')],
    )]
    public function basket(): void {}

    #[OA\Get(
        path: '/countries/{countryCode}/fx',
        tags: ['reference'],
        summary: 'Official and parallel exchange rates',
        description: 'Both rates and the premium between them. The gap is itself a headline indicator of economic stress.',
        parameters: [
            new OA\Parameter(name: 'countryCode', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function fx(): void {}

    #[OA\Get(
        path: '/countries/{countryCode}/index/current',
        tags: ['index'],
        summary: 'Latest snapshot per location',
        description: 'The most recent snapshot for each location individually, so a location that has not reported today is still returned with its last known figure rather than dropped.',
        parameters: [new OA\Parameter(name: 'countryCode', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/IndexSnapshot')),
        ]))],
    )]
    public function current(): void {}

    #[OA\Get(
        path: '/locations/{locationSlug}/index',
        tags: ['index'],
        summary: 'Time series for one location',
        parameters: [
            new OA\Parameter(name: 'locationSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function history(): void {}

    #[OA\Get(
        path: '/locations/{locationSlug}/index/{date}',
        tags: ['index'],
        summary: 'One snapshot with its full item breakdown',
        parameters: [
            new OA\Parameter(name: 'locationSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 404, description: 'No snapshot for that date')],
    )]
    public function show(): void {}

    #[OA\Get(
        path: '/countries/{countryCode}/coverage',
        tags: ['index'],
        summary: 'Coverage and freshness per location',
        description: 'Published so a consumer can judge the data before using it.',
        parameters: [new OA\Parameter(name: 'countryCode', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function coverage(): void {}

    #[OA\Get(
        path: '/countries/{countryCode}/export.csv',
        tags: ['index'],
        summary: 'Bulk CSV export, streamed',
        description: 'Rate limited more tightly than ordinary reads. Carries the data licence in an X-Qeema-License header, because a CSV passed on loses the context the API page carried.',
        parameters: [
            new OA\Parameter(name: 'countryCode', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(
                name: 'hxl',
                in: 'query',
                description: 'Add a HXL hashtag row beneath the header, making the file directly ingestible by humanitarian data tooling (HDX, the HXL Proxy, libhxl). Off by default: to a parser that has not been told about HXL the tag row is an ordinary data row, so emitting it unconditionally would change what existing consumers parse.',
                schema: new OA\Schema(type: 'boolean', default: false),
            ),
        ],
        responses: [new OA\Response(response: 200, description: 'CSV')],
    )]
    public function export(): void {}

    #[OA\Post(
        path: '/submissions',
        tags: ['submissions'],
        summary: 'Report a price',
        description: 'The only write route. Unauthenticated because requiring a signup would suppress the participation the platform runs on. A repeated client_idempotency_key returns 200 with status "duplicate" rather than creating a second row.',
        responses: [
            new OA\Response(response: 201, description: 'Accepted'),
            new OA\Response(response: 200, description: 'Duplicate — already recorded'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function submit(): void {}

    #[OA\Get(path: '/health', tags: ['ops'], summary: 'Service health', responses: [new OA\Response(response: 200, description: 'OK')])]
    public function health(): void {}
}
