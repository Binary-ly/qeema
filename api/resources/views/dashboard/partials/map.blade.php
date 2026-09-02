{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    An inline SVG choropleth-by-point.

    Deliberately not MapLibre GL (PLAN.md §7.4 originally said otherwise; see
    D-10 for why that changed). Three reasons decided it:

    1. There are no boundary polygons. Locations are points, so a WebGL engine
       would ship ~230 KB gzipped and a WebGL requirement to draw sixteen
       circles.
    2. A canvas is opaque to assistive technology. Every point below is a real
       DOM node with an accessible name, reachable by keyboard.
    3. WebGL is unreliable on the low-end Android hardware common in the places
       this platform measures. SVG is not.

    The projection is fitted to the country's own bounding box, so nothing here
    knows which country it is drawing (constraint C3).
--}}
<figure class="dash__map-figure">
    <svg
        class="dash__map"
        viewBox="0 0 {{ $projection->width }} {{ $projection->height }}"
        role="group"
        aria-labelledby="map-h"
        aria-describedby="map-desc"
        preserveAspectRatio="xMidYMid meet"
    >
        <desc id="map-desc">{{ __('dashboard.map_alt') }}</desc>

        {{-- The country itself, drawn first so everything else sits on top of
             land rather than on nothing. Purely decorative: it carries no data,
             so it is hidden from assistive technology, which reads the points
             and the table instead. --}}
        @foreach ($outline as $ring)
            <path class="dash__map-land" d="{{ $ring }}" aria-hidden="true"></path>
        @endforeach

        {{-- Drawn largest-first so small points are never buried under big ones. --}}
        @foreach (collect($points)->sortByDesc('cost') as $point)
            @php
                $intensity = $point['intensity'];
                $radius = 9;

                // Chosen in DashboardData, not here: the label spacing is
                // computed from the width of this exact string, so the two must
                // never be able to disagree about which name is on the map.
                $label = $point['label'];
            @endphp

            <g class="dash__map-point">
                <a
                    href="#row-{{ $point['slug'] }}"
                    aria-label="{{ $label }}: {{ number_format($point['cost'], 2) }} {{ $headline['currency'] }}{{ $point['comparable'] ? '' : ' — '.__('dashboard.comparable_note') }}"
                >
                    <circle
                        cx="{{ $point['x'] }}"
                        cy="{{ $point['y'] }}"
                        r="{{ $radius }}"
                        class="dash__map-dot{{ $point['comparable'] ? '' : ' dash__map-dot--incomparable' }}"
                        @if ($intensity !== null)
                            {{-- Interpolated in CSS custom-property space so the
                                 palette lives in the stylesheet, not in markup. --}}
                            style="--intensity: {{ $intensity }}"
                        @endif
                    ></circle>

                    <title>{{ $label }} — {{ number_format($point['cost'], 2) }} {{ $headline['currency'] }}</title>
                </a>

                {{-- Dropped when both the above and below slots are already
                     taken by a neighbour. Every location is named in full in
                     the table below, so a missing label costs nothing; four
                     labels printed on the same pixels cost the whole map. --}}
                @if ($point['label_show'] ?? true)
                    <text
                        class="dash__map-label"
                        x="{{ $point['x'] }}"
                        y="{{ $point['y'] + ($point['label_dy'] ?? -14) }}"
                        text-anchor="middle"
                        aria-hidden="true"
                    >{{ $label }}</text>
                @endif
            </g>
        @endforeach
    </svg>

    <figcaption class="dash__sr-only">{{ __('dashboard.map_desc') }}</figcaption>
</figure>
