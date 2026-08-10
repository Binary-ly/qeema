<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\OpenApi;

use OpenApi\Attributes as OA;

/**
 * The OpenAPI 3 description of the public API.
 *
 * Kept in one file rather than scattered across controllers so the published
 * contract can be read end to end by a person deciding whether to build on it.
 * The generated document is validated in CI and every API test asserts its
 * responses against the same shapes.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Qeema — Open Affordability Index',
    description: <<<'TEXT'
    A live, child-weighted affordability index for crisis economies.

    Every read endpoint is unauthenticated: the data being open is the point.
    Reads are rate limited per IP; bulk export more tightly still.

    **Reading the numbers honestly.** Every price-bearing object carries
    `is_imputed`. An imputed value is an estimate produced by a model, never a
    measurement, and is never silently mixed with observed data. Snapshots also
    carry `coverage`, `imputed_share` and `comparable` — `comparable` is false
    until every basket item has a price, and a consumer ranking locations must
    check it, because a partially-observed basket costs less simply because part
    of it is missing.

    `cost.usd` is null when no exchange rate within the configured staleness
    horizon was available. That is a deliberate refusal to invent a conversion,
    not missing data.
    TEXT,
    license: new OA\License(name: 'Apache-2.0', url: 'https://www.apache.org/licenses/LICENSE-2.0'),
)]
#[OA\Server(url: '/api/v1', description: 'Public API v1')]
#[OA\Tag(name: 'index', description: 'The published affordability index')]
#[OA\Tag(name: 'reference', description: 'Countries, locations, basket composition and exchange rates')]
#[OA\Tag(name: 'submissions', description: 'Inbound price reports')]
#[OA\Tag(name: 'ops', description: 'Health and readiness')]
#[OA\Schema(
    schema: 'Quality',
    description: 'How far a published figure can be trusted. Never omitted.',
    required: ['coverage', 'imputed_share', 'comparable', 'label'],
    properties: [
        new OA\Property(property: 'coverage', type: 'number', format: 'float', description: 'Share of basket weight backed by real observations.'),
        new OA\Property(property: 'imputed_share', type: 'number', format: 'float', description: 'Share of basket weight that was estimated.'),
        new OA\Property(property: 'observed_items', type: 'integer'),
        new OA\Property(property: 'total_items', type: 'integer'),
        new OA\Property(property: 'label', type: 'string', enum: ['good', 'moderate', 'low']),
        new OA\Property(property: 'comparable', type: 'boolean', description: 'False until every basket item has a price. Check before ranking locations.'),
    ],
)]
#[OA\Schema(
    schema: 'Cost',
    required: ['local', 'currency'],
    properties: [
        new OA\Property(property: 'local', type: 'number', format: 'float'),
        // ISO 4217, whichever the deployment configures. Deliberately not a
        // real shipped currency: an example naming one country's money would
        // be a C3 violation in the published contract.
        new OA\Property(property: 'currency', type: 'string', example: 'XTS'),
        new OA\Property(property: 'usd', type: 'number', format: 'float', nullable: true, description: 'Null when no usable exchange rate existed.'),
        new OA\Property(property: 'confidence_low', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'confidence_high', type: 'number', format: 'float', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'SnapshotItem',
    required: ['is_imputed', 'unit_price', 'observation_count'],
    properties: [
        new OA\Property(property: 'is_imputed', type: 'boolean', description: 'True when this price was estimated rather than observed. Always present.'),
        new OA\Property(property: 'imputation_method', type: 'string', nullable: true),
        new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
        new OA\Property(property: 'quantity', type: 'number', format: 'float'),
        new OA\Property(property: 'weight', type: 'number', format: 'float'),
        new OA\Property(property: 'contribution', type: 'number', format: 'float'),
        new OA\Property(property: 'confidence_low', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'confidence_high', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'observation_count', type: 'integer', description: 'Zero on an imputed price, by construction.'),
    ],
)]
#[OA\Schema(
    schema: 'IndexSnapshot',
    required: ['date', 'cost', 'quality', 'exchange_rate'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'cost', ref: '#/components/schemas/Cost'),
        new OA\Property(property: 'quality', ref: '#/components/schemas/Quality'),
        new OA\Property(
            property: 'exchange_rate',
            properties: [
                new OA\Property(property: 'rate', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'type', type: 'string', nullable: true, enum: ['parallel', 'official', null]),
                new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'is_stale', type: 'boolean'),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/SnapshotItem')),
    ],
)]
final class Definition {}
