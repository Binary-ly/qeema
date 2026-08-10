{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('dashboard.title') }} — {{ $country->name }}</title>
    <meta name="description" content="{{ __('dashboard.tagline') }}">

    {{-- The data is the product, so let it be found and syndicated. --}}
    <link rel="alternate" type="application/json" href="{{ $apiUrl }}">
    <link rel="canonical" href="{{ url()->current() }}">

    @vite(['resources/css/dashboard.css'])

    {{--
        Charts are deferred, not blocking. The page is complete and correct
        before this runs; ECharts only replaces already-rendered fallbacks.
    --}}
    @vite(['resources/js/dashboard.js'])
</head>

{{--
    Logical properties throughout (inline-start, margin-inline) rather than
    left/right, so one stylesheet is correct in both directions. A mirrored
    stylesheet drifts out of sync the first time someone edits only one.
--}}
<body class="dash">

<a class="dash__skip" href="#main">{{ __('dashboard.skip_to_content') }}</a>

<header class="dash__header">
    <div class="dash__header-inner">
        <div>
            <h1 class="dash__title">{{ __('dashboard.title') }}</h1>
            <p class="dash__tagline">{{ __('dashboard.tagline') }}</p>
        </div>

        <nav class="dash__nav" aria-label="{{ __('dashboard.language') }}">
            @if (count($countries) > 1)
                <label class="dash__field">
                    <span class="dash__field-label">{{ $country->name }}</span>
                    {{-- No inline handler: an onchange attribute would force
                         'unsafe-inline' into the script-src policy, which is
                         most of what a CSP is for. Wired up in dashboard.js. --}}
                    <select class="dash__select" id="country-picker" data-locale="{{ $locale }}">
                        @foreach ($countries as $option)
                            <option value="{{ $option->code }}" @selected($option->code === $country->code)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if (count($availableLocales) > 1)
                <ul class="dash__locales">
                    @foreach ($availableLocales as $option)
                        <li>
                            <a
                                href="?country={{ $country->code }}&locale={{ $option }}"
                                @if ($option === $locale) aria-current="true" @endif
                                lang="{{ $option }}"
                            >{{ strtoupper($option) }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>
    </div>
</header>

<main id="main" class="dash__main">

    {{-- The empty state means "nothing published yet", not "nothing rankable".
         When snapshots exist but none is comparable, the map, the table and the
         charts are all still true and still useful — only the headline median
         is undefined. Hiding the page in that case would withhold real data
         because one derived figure could not be computed. --}}
    @if ($points === [])
        <section class="dash__empty">
            <h2>{{ __('dashboard.no_data') }}</h2>
            <p>{{ __('dashboard.no_data_body') }}</p>
        </section>
    @else
        {{-- ---------------------------------------------------------------
             Headline. Every figure carries the caveat that makes it readable
             rather than leaving the caveat to a footnote nobody reaches.
        ---------------------------------------------------------------- --}}
        <section class="dash__headline" aria-labelledby="headline-h">
            <h2 id="headline-h" class="dash__section-title">
                {{ __('dashboard.headline_median') }}
            </h2>

            @if ($headline['median_cost'] === null)
                {{-- No location has a fully-priced basket, so there is no
                     honest median to publish. Saying that plainly beats
                     printing a figure drawn from partial baskets. --}}
                <p class="dash__figure dash__figure--none">—</p>
                <p class="dash__caveat">{{ __('dashboard.no_comparable') }}</p>
            @else
                <p class="dash__figure">
                    <strong>{{ number_format($headline['median_cost'], 2) }}</strong>
                    <span class="dash__unit">{{ $headline['currency'] }}</span>
                </p>
            @endif

            <dl class="dash__stats">
                @if ($headline['median_cost_usd'] !== null)
                    <div class="dash__stat">
                        <dt>{{ __('dashboard.headline_usd') }}</dt>
                        <dd>${{ number_format($headline['median_cost_usd'], 2) }}</dd>
                    </div>
                @endif

                @if ($headline['spread'] !== null)
                    <div class="dash__stat">
                        <dt>{{ __('dashboard.headline_spread') }}</dt>
                        <dd>+{{ number_format($headline['spread'] * 100, 0) }}%</dd>
                    </div>
                @endif

                <div class="dash__stat">
                    <dt>{{ __('dashboard.headline_locations') }}</dt>
                    <dd>{{ $headline['locations_comparable'] }} / {{ $headline['locations_total'] }}</dd>
                </div>

                <div class="dash__stat">
                    <dt>{{ __('dashboard.imputed') }}</dt>
                    <dd>{{ number_format($headline['mean_imputed_share'] * 100, 0) }}%</dd>
                </div>
            </dl>

            @if ($headline['as_of'])
                <p class="dash__asof">{{ __('dashboard.as_of', ['date' => $headline['as_of']]) }}</p>
            @endif

            @if ($headline['incomparable'] > 0)
                <p class="dash__caveat">
                    <strong>{{ __('dashboard.comparable_note') }}:</strong>
                    {{ __('dashboard.comparable_explain') }}
                </p>
            @endif
        </section>

        {{-- ---------------------------------------------------------------
             Map. Inline SVG rather than a WebGL canvas: every point is a real
             DOM element, so it lands in the accessibility tree, is reachable by
             keyboard, and survives with JavaScript disabled. A canvas map is
             invisible to a screen reader and would need a parallel table built
             alongside it anyway. See D-10.
        ---------------------------------------------------------------- --}}
        <section class="dash__panel" aria-labelledby="map-h">
            <h2 id="map-h" class="dash__section-title">{{ __('dashboard.map_title') }}</h2>
            <p class="dash__section-desc">{{ __('dashboard.map_desc') }}</p>

            @include('dashboard.partials.map')

            <ul class="dash__legend">
                <li><span class="dash__swatch dash__swatch--low" aria-hidden="true"></span>{{ __('dashboard.legend_cheaper') }}</li>
                <li><span class="dash__swatch dash__swatch--high" aria-hidden="true"></span>{{ __('dashboard.legend_dearer') }}</li>
                <li><span class="dash__swatch dash__swatch--none" aria-hidden="true"></span>{{ __('dashboard.legend_incomparable') }}</li>
            </ul>
        </section>

        {{-- ---------------------------------------------------------------
             Charts. Each renders a server-side fallback first — a plain
             statement of the latest value — which ECharts replaces once it
             loads. Nothing here is blank without JavaScript.
        ---------------------------------------------------------------- --}}
        <section class="dash__panel" aria-labelledby="chart-national-h">
            <h2 id="chart-national-h" class="dash__section-title">{{ __('dashboard.chart_national') }}</h2>
            <p class="dash__section-desc">{{ __('dashboard.chart_national_desc') }}</p>

            <div class="dash__chart" id="chart-national" role="img"
                 aria-label="{{ __('dashboard.chart_national') }}: {{ __('dashboard.chart_national_desc') }}">
                @include('dashboard.partials.series-fallback', [
                    'series' => $charts['national'],
                    'currency' => $charts['currency'],
                ])
            </div>
        </section>

        @if ($charts['fx'] !== [])
            <section class="dash__panel" aria-labelledby="chart-fx-h">
                <h2 id="chart-fx-h" class="dash__section-title">{{ __('dashboard.chart_fx') }}</h2>
                <p class="dash__section-desc">{{ __('dashboard.chart_fx_desc') }}</p>

                <div class="dash__chart" id="chart-fx" role="img"
                     aria-label="{{ __('dashboard.chart_fx') }}: {{ __('dashboard.chart_fx_desc') }}">
                    @php $latestFx = end($charts['fx']); @endphp
                    @if ($latestFx && $latestFx['premium'] !== null)
                        <p class="dash__fallback">
                            {{ __('dashboard.chart_premium') }}:
                            <strong>{{ number_format($latestFx['premium'] * 100, 1) }}%</strong>
                            ({{ __('dashboard.chart_official') }} {{ number_format((float) $latestFx['official'], 2) }},
                            {{ __('dashboard.chart_parallel') }} {{ number_format((float) $latestFx['parallel'], 2) }})
                        </p>
                    @endif
                </div>
            </section>
        @endif

        {{-- ---------------------------------------------------------------
             The table is not a fallback for the map — it is the accessible
             equal of it, and carries more than the map can show.
        ---------------------------------------------------------------- --}}
        <section class="dash__panel" aria-labelledby="table-h">
            <h2 id="table-h" class="dash__section-title">{{ __('dashboard.table_title') }}</h2>

            <div class="dash__table-scroll">
                <table class="dash__table">
                    <caption class="dash__sr-only">{{ __('dashboard.table_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('dashboard.table_location') }}</th>
                            <th scope="col">{{ __('dashboard.table_cost') }}</th>
                            <th scope="col">{{ __('dashboard.table_coverage') }}</th>
                            <th scope="col">{{ __('dashboard.imputed') }}</th>
                            <th scope="col">{{ __('dashboard.table_quality') }}</th>
                            <th scope="col">{{ __('dashboard.table_updated') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($points as $point)
                            @php $rowClass = $point['comparable'] ? '' : ' dash__row--incomparable'; @endphp
                            <tr id="row-{{ $point['slug'] }}" class="dash__row{{ $rowClass }}">
                                <th scope="row">
                                    {{ $point['name'] }}
                                    @if ($point['name_local'] && $point['name_local'] !== $point['name'])
                                        <span class="dash__name-local">{{ $point['name_local'] }}</span>
                                    @endif
                                </th>
                                <td>
                                    {{ number_format($point['cost'], 2) }}
                                    @unless ($point['comparable'])
                                        <span class="dash__badge" title="{{ __('dashboard.comparable_explain') }}">
                                            {{ __('dashboard.comparable_note') }}
                                        </span>
                                    @endunless
                                </td>
                                <td>{{ number_format($point['coverage'] * 100, 0) }}%</td>
                                <td>{{ number_format($point['imputed_share'] * 100, 0) }}%</td>
                                <td>
                                    <span class="dash__quality dash__quality--{{ $point['quality'] }}">
                                        {{ __('dashboard.quality_'.$point['quality']) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($point['days_old'] <= 0)
                                        {{ __('dashboard.today') }}
                                    @else
                                        {{ trans_choice('dashboard.days_ago', $point['days_old'], ['count' => $point['days_old']]) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="dash__note">{{ __('dashboard.imputed_explain') }}</p>
        </section>
    @endif

    <section class="dash__panel dash__panel--cta" aria-labelledby="use-h">
        <h2 id="use-h" class="dash__section-title">{{ __('dashboard.use_the_data') }}</h2>
        <p>{{ __('dashboard.use_the_data_body') }}</p>

        <ul class="dash__links">
            <li><a href="{{ route('docs') }}">{{ __('dashboard.api_link') }}</a></li>
            <li><a href="{{ $apiUrl }}">{{ __('dashboard.json_link') }}</a></li>
            <li><a href="{{ $exportUrl }}">{{ __('dashboard.csv_link') }}</a></li>
        </ul>
    </section>
</main>

{{-- Chart data as JSON, read by the deferred script. Not fetched: the server
     already has it, and a second round trip would delay the chart for no gain.

     Built as a variable first, rather than passed inline as a literal: Blade's
     directive argument parser truncates a multi-line array literal containing
     calls, which silently emitted malformed JSON. --}}
@php
    $chartLabels = [
        'official' => __('dashboard.chart_official'),
        'parallel' => __('dashboard.chart_parallel'),
        'premium' => __('dashboard.chart_premium'),
        'cost' => __('dashboard.table_cost'),
        'rtl' => $direction === 'rtl',
    ];
@endphp
<script type="application/json" id="dash-charts">@json($charts)</script>
<script type="application/json" id="dash-labels">@json($chartLabels)</script>

</body>
</html>
