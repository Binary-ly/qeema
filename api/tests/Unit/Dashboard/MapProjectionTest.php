<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Services\Dashboard\MapProjection;

/**
 * @param  array{0: float, 1: float}  ...$pairs
 * @return list<array{latitude: float, longitude: float}>
 */
function mapPoints(array ...$pairs): array
{
    return array_map(
        static fn (array $p): array => ['latitude' => $p[0], 'longitude' => $p[1]],
        $pairs,
    );
}

it('places every point inside the canvas', function (): void {
    $points = mapPoints([32.88, 13.19], [32.11, 20.06], [24.21, 23.29], [31.20, 16.58]);
    $projection = MapProjection::fit($points, 800, 500);

    foreach ($points as $point) {
        $xy = $projection->project($point['latitude'], $point['longitude']);

        expect($xy['x'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(800.0)
            ->and($xy['y'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(500.0);
    }
});

it('draws north above south', function (): void {
    // SVG y grows downward while latitude grows upward. Getting this wrong
    // renders the map upside down, which reads as plausible until someone who
    // knows the country looks at it.
    $projection = MapProjection::fit(mapPoints([35.0, 10.0], [25.0, 20.0]));

    $north = $projection->project(35.0, 10.0);
    $south = $projection->project(25.0, 20.0);

    expect($north['y'])->toBeLessThan($south['y']);
});

it('draws east to the right of west', function (): void {
    $projection = MapProjection::fit(mapPoints([30.0, 10.0], [30.0, 20.0]));

    expect($projection->project(30.0, 20.0)['x'])
        ->toBeGreaterThan($projection->project(30.0, 10.0)['x']);
});

it('preserves aspect ratio rather than stretching to fill', function (): void {
    // A country twice as wide as it is tall must stay that way. Scaling each
    // axis independently would fill the box but distort the country's shape.
    $projection = MapProjection::fit(mapPoints([30.0, 0.0], [40.0, 20.0]), 800, 500, 0.0);

    $topLeft = $projection->project(40.0, 0.0);
    $bottomRight = $projection->project(30.0, 20.0);

    $drawnWidth = $bottomRight['x'] - $topLeft['x'];
    $drawnHeight = $bottomRight['y'] - $topLeft['y'];

    $lonScale = cos(deg2rad(35.0));
    $trueRatio = (20.0 * $lonScale) / 10.0;

    expect(abs($drawnWidth / $drawnHeight - $trueRatio))->toBeLessThan(0.01);
});

it('corrects for meridian convergence away from the equator', function (): void {
    // Ten degrees of longitude spans less ground at 60°N than at the equator.
    // Without the cosine term a northern country is drawn stretched east-west.
    $equator = MapProjection::fit(mapPoints([0.0, 0.0], [10.0, 10.0]), 800, 500, 0.0);
    $northern = MapProjection::fit(mapPoints([55.0, 0.0], [65.0, 10.0]), 800, 500, 0.0);

    $equatorWidth = $equator->project(0.0, 10.0)['x'] - $equator->project(0.0, 0.0)['x'];
    $northernWidth = $northern->project(55.0, 10.0)['x'] - $northern->project(55.0, 0.0)['x'];

    expect($northernWidth)->toBeLessThan($equatorWidth);
});

it('handles a single location without dividing by zero', function (): void {
    // One reporting location is a real state for a freshly seeded deployment.
    $projection = MapProjection::fit(mapPoints([32.0, 13.0]), 800, 500);
    $xy = $projection->project(32.0, 13.0);

    expect(is_finite($xy['x']))->toBeTrue()
        ->and(is_finite($xy['y']))->toBeTrue();
});

it('handles no locations at all', function (): void {
    $projection = MapProjection::fit([], 800, 500);

    expect($projection->project(0.0, 0.0))->toHaveKeys(['x', 'y']);
});

it('reports separation so overlapping labels can be detected', function (): void {
    $projection = MapProjection::fit(mapPoints([32.0, 13.0], [32.1, 13.1], [24.0, 23.0]));

    $close = $projection->separation(32.0, 13.0, 32.1, 13.1);
    $far = $projection->separation(32.0, 13.0, 24.0, 23.0);

    expect($close)->toBeLessThan($far);
});
