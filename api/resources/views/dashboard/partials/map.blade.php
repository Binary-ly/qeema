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

        {{-- Drawn largest-first so small points are never buried under big ones. --}}
        @foreach (collect($points)->sortByDesc('cost') as $point)
            @php
                $intensity = $point['intensity'];
                $radius = 9;
            @endphp

            <g class="dash__map-point">
                <a
                    href="#row-{{ $point['slug'] }}"
                    aria-label="{{ $point['name'] }}: {{ number_format($point['cost'], 2) }} {{ $headline['currency'] }}{{ $point['comparable'] ? '' : ' — '.__('dashboard.comparable_note') }}"
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

                    <title>{{ $point['name'] }} — {{ number_format($point['cost'], 2) }} {{ $headline['currency'] }}</title>
                </a>

                <text
                    class="dash__map-label"
                    x="{{ $point['x'] }}"
                    y="{{ $point['y'] - 14 }}"
                    text-anchor="middle"
                    aria-hidden="true"
                >{{ $point['name'] }}</text>
            </g>
        @endforeach
    </svg>

    <figcaption class="dash__sr-only">{{ __('dashboard.map_desc') }}</figcaption>
</figure>
