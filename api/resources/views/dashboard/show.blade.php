{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
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

@php
    // Hero counters. Computed here rather than in the controller because they
    // are a presentation summary of data the page already has, and because the
    // header renders before the empty-state branch — every one of these has to
    // survive a deployment with nothing published yet.
    $basketTotal = count($basket ?? []);
    $basketPriced = collect($basket ?? [])->where('locations', '>', 0)->count();
@endphp

<header class="dash__header">
    <div class="dash__header-inner">
        <div class="dash__masthead">
            @include('partials.brand')
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

    {{-- The rail. Three true numbers above the fold, in the masthead, before a
         reader has scrolled anything. The page used to open on a headline and a
         sentence, which told somebody what this is without telling them
         anything it had found. --}}
    <div class="dash__rail">
        <div class="dash__rail-inner">
            @if ($basketTotal > 0)
                <div class="dash__rail-stat">
                    {{-- The markup already holds the final value; the count-up
                         animates toward what is written rather than filling in
                         a blank, so the number is correct with JavaScript off
                         and correct again the instant the animation ends. --}}
                    <span class="dash__rail-value"><span data-count="{{ $basketPriced }}">{{ $basketPriced }}</span><span class="dash__rail-of">/{{ $basketTotal }}</span></span>
                    <span class="dash__rail-label">{{ __('dashboard.hero_items') }}</span>
                </div>
            @endif

            <div class="dash__rail-stat">
                <span class="dash__rail-value" data-count="{{ count($points) }}">{{ count($points) }}</span>
                <span class="dash__rail-label">{{ __('dashboard.hero_locations') }}</span>
            </div>

            @if (! empty($headline['as_of']))
                <div class="dash__rail-stat">
                    <span class="dash__rail-value dash__rail-value--date">{{ $headline['as_of'] }}</span>
                    <span class="dash__rail-label">{{ __('dashboard.hero_updated') }}</span>
                </div>
            @endif
        </div>
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

            {{-- Two explicit columns. Without them the grid auto-places each
                 child into the next free cell, so the figure, the stats and the
                 two caveats alternated left and right down the page and read as
                 four unrelated fragments. --}}
            <div class="dash__headline-main">
            @if ($headline['median_cost'] === null)
                {{-- Nothing is printed here. There is no honest median to
                     publish, the panel alongside says so in words, and the
                     strip below shows every location that *does* have a figure.
                     An em-dash set at the size of the missing number just reads
                     as a number that failed to render. --}}
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

            {{-- Every reporting location on one axis, positioned by what its
                 basket actually costs.

                 The section used to be an em-dash and two counters whenever no
                 location had a fully-priced basket — which is most of the time
                 early in a deployment, and is exactly when a reader most wants
                 to know what *is* known. These are the same figures the table
                 below publishes, arranged so the spread is visible at a glance
                 instead of having to be read out of sixteen rows.

                 Hollow dots carry the same meaning as on the map: present, and
                 deliberately outside any ranking. --}}
            @php
                $priced = collect($points)->filter(fn ($p) => $p['cost'] > 0)->values();
                $stripMin = $priced->min('cost');
                $stripMax = $priced->max('cost');
                $stripSpan = max(($stripMax ?? 0) - ($stripMin ?? 0), 1e-9);
            @endphp
            @if ($priced->count() > 1)
                <figure class="strip">
                    <figcaption class="strip__caption">{{ __('dashboard.table_cost') }} — {{ $headline['currency'] }}</figcaption>
                    <div class="strip__track">
                        @foreach ($priced as $p)
                            <span
                                class="strip__dot{{ $p['comparable'] ? '' : ' strip__dot--hollow' }}"
                                style="--p: {{ round((($p['cost'] - $stripMin) / $stripSpan) * 100, 2) }}%"
                                title="{{ $p['name'] }} — {{ number_format($p['cost'], 2) }} {{ $headline['currency'] }}"
                            ></span>
                        @endforeach
                    </div>
                    <div class="strip__scale">
                        <span>{{ number_format($stripMin, 2) }}</span>
                        <span>{{ number_format($stripMax, 2) }}</span>
                    </div>
                </figure>
            @endif

            @if ($headline['as_of'])
                <p class="dash__asof">{{ __('dashboard.as_of', ['date' => $headline['as_of']]) }}</p>
            @endif
            </div>

            {{-- The qualifiers sit beside the figure rather than beneath it. A
                 cost at 39% coverage is a different claim from the same cost at
                 95%, and a reader should not have to scroll to find out which
                 one they are looking at. --}}
            <div class="dash__headline-notes">
                @if ($headline['median_cost'] === null)
                    <p class="dash__caveat">{{ __('dashboard.no_comparable') }}</p>
                @endif

                @if ($headline['incomparable'] > 0)
                    <p class="dash__caveat">
                        <strong>{{ __('dashboard.comparable_note') }}:</strong>
                        {{ __('dashboard.comparable_explain') }}
                    </p>
                @endif
            </div>
        </section>

        {{-- ---------------------------------------------------------------
             The basket itself.

             The page published a cost for years without ever showing what was
             being costed. The composition is a judgement rather than a fact, so
             publishing the total while hiding the list asks the reader to trust
             it instead of letting them check it.

             It is also where the gap is stated: an item with no price in any
             location is not a rendering fault, it is a category of thing a
             child needs that nothing in this deployment tracks.
        ---------------------------------------------------------------- --}}
        @if (! empty($basket))
            @php
                $unpriced = collect($basket)->where('locations', 0)->count();
                // Bars are scaled to the heaviest item rather than to 1.0.
                // Fifteen weights summing to one means the largest is about a
                // seventh, so true-scale bars would all be slivers. The printed
                // percentage beside each bar is the real number.
                $maxWeight = max(collect($basket)->max('weight'), 0.0001);
            @endphp
            <section class="dash__panel" aria-labelledby="basket-h">
                <h2 id="basket-h" class="dash__section-title">{{ __('dashboard.basket_title') }}</h2>
                <p class="dash__section-desc">{{ __('dashboard.basket_desc') }}</p>

                {{-- One bar, the whole basket, segmented by weight. This is the
                     single image the page exists to show: the coloured run is
                     what a child needs that anyone can price, and the hatched
                     run is what nobody can. Reading it takes no numeracy and no
                     scrolling, which the fifteen-row list below does. --}}
                @php
                    $gapWeight = collect($basket)->where('locations', 0)->sum('weight');
                @endphp
                <div class="basket__stack" role="img"
                     aria-label="{{ __('dashboard.basket_stack_label', ['percent' => number_format($gapWeight * 100, 0)]) }}">
                    @foreach ($basket as $entry)
                        <span
                            class="basket__seg{{ $entry['locations'] === 0 ? ' basket__seg--unpriced' : '' }}"
                            style="--w: {{ round($entry['weight'] * 100, 3) }}%"
                            title="{{ $entry['name'] }} — {{ number_format($entry['weight'] * 100, 1) }}%"
                        ></span>
                    @endforeach
                </div>
                <p class="basket__stack-caption">
                    {{ __('dashboard.basket_stack_label', ['percent' => number_format($gapWeight * 100, 0)]) }}
                </p>

                <ol class="basket">
                    @foreach ($basket as $entry)
                        <li class="basket__item{{ $entry['locations'] === 0 ? ' basket__item--unpriced' : '' }}">
                            <span class="basket__bar" style="--weight: {{ round($entry['weight'] / $maxWeight * 100, 1) }}%" aria-hidden="true"></span>
                            <span class="basket__name">
                                {{ $entry['name'] }}
                                @if ($entry['name_local'])
                                    <span class="basket__name-local">{{ $entry['name_local'] }}</span>
                                @endif
                            </span>
                            <span class="basket__weight">{{ number_format($entry['weight'] * 100, 1) }}%</span>
                            <span class="basket__where">
                                @if ($entry['locations'] === 0)
                                    {{ __('dashboard.basket_none') }}
                                @else
                                    {{ __('dashboard.basket_locations', ['count' => $entry['locations'], 'total' => $entry['total_locations']]) }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>

                @if ($unpriced > 0)
                    <p class="dash__caveat">
                        {{ __('dashboard.basket_gap', ['count' => $unpriced, 'total' => count($basket)]) }}
                    </p>
                @endif
            </section>
        @endif

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
        @php
            // Scale for the in-cell bars. Recomputed here rather than reusing
            // the strip's, so the table stands on its own if either section is
            // moved or removed.
            $tableMax = collect($points)->max('cost') ?: 0;
        @endphp
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
                                {{-- A bar behind the figure, scaled across the
                                     column. Sixteen right-aligned numbers two
                                     decimal places apart are accurate and
                                     unscannable; the bar makes the spread
                                     visible without asking anyone to read a
                                     second chart. It is drawn behind the text
                                     and hidden from assistive technology — the
                                     number is the content. --}}
                                <td class="cell--num">
                                    <span class="cell-bar" aria-hidden="true"
                                          style="--v: {{ $tableMax > 0 ? round(($point['cost'] / $tableMax) * 100, 1) : 0 }}%"></span>
                                    <span class="cell-val">
                                        {{-- A location with nothing priced has
                                             no cost, and 0.00 is a measurement
                                             saying the basket is free. An
                                             em-dash is the absence it actually
                                             is. --}}
                                        {{ $point['cost'] > 0 ? number_format($point['cost'], 2) : '—' }}
                                        @unless ($point['comparable'])
                                            <span class="dash__badge" title="{{ __('dashboard.comparable_explain') }}">
                                                {{ __('dashboard.comparable_note') }}
                                            </span>
                                        @endunless
                                    </span>
                                </td>
                                <td class="cell--num">
                                    <span class="cell-bar cell-bar--soft" aria-hidden="true"
                                          style="--v: {{ round($point['coverage'] * 100, 1) }}%"></span>
                                    <span class="cell-val">{{ number_format($point['coverage'] * 100, 0) }}%</span>
                                </td>
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
