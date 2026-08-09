<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * Projects longitude/latitude onto SVG coordinates.
 *
 * Equirectangular with a cosine correction at the country's mean latitude.
 * A full projection library would be the wrong dependency here: at the scale of
 * one country the distortion is imperceptible, and the correction below is the
 * part that actually matters — without it a country far from the equator is
 * drawn noticeably stretched east-to-west.
 *
 * The bounding box comes from the plotted locations themselves, so this holds
 * nothing about any particular country (constraint C3).
 */
final readonly class MapProjection
{
    private function __construct(
        public float $minLon,
        public float $maxLon,
        public float $minLat,
        public float $maxLat,
        public float $width,
        public float $height,
        private float $scale,
        private float $offsetX,
        private float $offsetY,
    ) {}

    /**
     * Fit a projection to a set of points.
     *
     * @param  list<array{latitude: float, longitude: float}>  $points
     */
    public static function fit(array $points, float $width = 800.0, float $height = 500.0, float $padding = 40.0): self
    {
        if ($points === []) {
            return new self(0, 0, 0, 0, $width, $height, 1.0, $width / 2, $height / 2);
        }

        $lons = array_column($points, 'longitude');
        $lats = array_column($points, 'latitude');

        $minLon = min($lons);
        $maxLon = max($lons);
        $minLat = min($lats);
        $maxLat = max($lats);

        // Correct for meridian convergence: a degree of longitude covers less
        // ground the further you are from the equator.
        $meanLat = ($minLat + $maxLat) / 2.0;
        $lonScale = cos(deg2rad($meanLat));

        $spanX = max(($maxLon - $minLon) * $lonScale, 1e-9);
        $spanY = max($maxLat - $minLat, 1e-9);

        $usableWidth = max($width - 2 * $padding, 1.0);
        $usableHeight = max($height - 2 * $padding, 1.0);

        // One scale for both axes, so the country keeps its true shape rather
        // than being stretched to fill the box.
        $scale = min($usableWidth / $spanX, $usableHeight / $spanY);

        // Centre whatever space the aspect ratio leaves over.
        $offsetX = ($width - $spanX * $scale) / 2.0;
        $offsetY = ($height - $spanY * $scale) / 2.0;

        return new self($minLon, $maxLon, $minLat, $maxLat, $width, $height, $scale, $offsetX, $offsetY);
    }

    /**
     * @return array{x: float, y: float}
     */
    public function project(float $latitude, float $longitude): array
    {
        $meanLat = ($this->minLat + $this->maxLat) / 2.0;
        $lonScale = cos(deg2rad($meanLat));

        $x = ($longitude - $this->minLon) * $lonScale * $this->scale + $this->offsetX;

        // SVG y grows downward; latitude grows upward. Flip, or the map is
        // drawn upside down.
        $y = ($this->maxLat - $latitude) * $this->scale + $this->offsetY;

        return ['x' => round($x, 2), 'y' => round($y, 2)];
    }

    /**
     * Distance in projected units between two points, for collision checks.
     */
    public function separation(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $a = $this->project($latA, $lonA);
        $b = $this->project($latB, $lonB);

        return sqrt(($a['x'] - $b['x']) ** 2 + ($a['y'] - $b['y']) ** 2);
    }
}
