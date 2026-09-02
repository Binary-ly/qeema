<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * The country's coastline and land border, for drawing the map frame.
 *
 * The map was points floating in an empty box until this existed. Sixteen
 * circles with no landmass behind them do not read as a map — they read as a
 * component that failed to load, which is how a reader described it. Geography
 * is most of what makes a per-location index legible: "expensive in the south"
 * is a sentence you can only see if the south is drawn.
 *
 * **It is country configuration, not code** (C3). The outlines live in
 * `countries/geometry/<code>.json` beside the country files, nothing here names
 * a country, and a deployment that adds a country file adds its outline the
 * same way. A country with no outline file still gets a working map — the frame
 * simply falls back to the bounding box of its own reporting locations, which
 * is what the map did before.
 *
 * Natural Earth 1:110m, public domain. Deliberately the coarsest tier: this is
 * drawn about 800 px wide, where a finer outline is more bytes for detail no
 * screen can resolve. The smaller of the two shipped outlines is 56 points and
 * about a kilobyte.
 *
 * It is a frame, never evidence. The file says so too — no boundary here is
 * asserted as authoritative, which matters in places where borders are disputed
 * and a map is not a neutral object.
 */
final readonly class CountryOutline
{
    /**
     * @param  list<list<array{latitude: float, longitude: float}>>  $rings
     */
    private function __construct(public array $rings) {}

    public static function forCountry(string $countryCode): self
    {
        $directory = (string) config('qeema.countries_path');
        $path = $directory.'/geometry/'.strtolower($countryCode).'.json';

        if (! is_file($path)) {
            return new self([]);
        }

        // Deliberately left as `mixed`. Annotating the decoded shape would be
        // asserting something about a file on disk that nothing has checked
        // yet, and it makes every guard below look redundant to static
        // analysis while doing no actual work at runtime.
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['rings']) || ! is_array($decoded['rings'])) {
            // A malformed outline degrades to no outline rather than to an
            // exception: the map is decoration around the figures, and no
            // decoration is worth a 500 on the public dashboard.
            return new self([]);
        }

        $rings = [];

        foreach ($decoded['rings'] as $ring) {
            if (! is_array($ring)) {
                continue;
            }

            $vertices = [];

            foreach ($ring as $vertex) {
                // GeoJSON order is [longitude, latitude] — the reverse of how
                // every other coordinate in this application is written, and a
                // silent source of maps drawn sideways.
                if (is_array($vertex) && array_key_exists(0, $vertex) && array_key_exists(1, $vertex)) {
                    $vertices[] = [
                        'latitude' => (float) $vertex[1],
                        'longitude' => (float) $vertex[0],
                    ];
                }
            }

            if (count($vertices) >= 3) {
                $rings[] = $vertices;
            }
        }

        return new self($rings);
    }

    public function isEmpty(): bool
    {
        return $this->rings === [];
    }

    /**
     * Every vertex, flattened — what the projection has to be fitted to so the
     * whole country is inside the frame rather than only its reporting towns.
     *
     * @return list<array{latitude: float, longitude: float}>
     */
    public function vertices(): array
    {
        if ($this->rings === []) {
            return [];
        }

        return array_merge(...$this->rings);
    }

    /**
     * The rings as SVG path data, projected.
     *
     * @return list<string>
     */
    public function paths(MapProjection $projection): array
    {
        $paths = [];

        foreach ($this->rings as $ring) {
            $commands = [];

            foreach ($ring as $index => $vertex) {
                $xy = $projection->project($vertex['latitude'], $vertex['longitude']);
                $commands[] = ($index === 0 ? 'M' : 'L').$xy['x'].' '.$xy['y'];
            }

            if ($commands !== []) {
                $paths[] = implode(' ', $commands).' Z';
            }
        }

        return $paths;
    }
}
