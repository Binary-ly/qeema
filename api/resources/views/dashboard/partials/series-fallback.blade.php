{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    What a chart container holds before (and without) JavaScript.

    Not a spinner and not empty: the latest value and the change across the
    window, stated in text. A reader on a dead connection still learns the thing
    the chart exists to convey.
--}}
@php
    $first = $series[0] ?? null;
    $last = $series !== [] ? $series[count($series) - 1] : null;
    $change = $first && $last && $first['cost'] > 0
        ? ($last['cost'] - $first['cost']) / $first['cost']
        : null;
@endphp

@if ($last === null)
    <p class="dash__fallback">{{ __('dashboard.chart_unavailable') }}</p>
@else
    <p class="dash__fallback">
        <strong>{{ number_format($last['cost'], 2) }} {{ $currency }}</strong>
        <span>({{ $last['date'] }})</span>
        @if ($change !== null)
            <span class="dash__delta">{{ $change >= 0 ? '+' : '' }}{{ number_format($change * 100, 1) }}%</span>
        @endif
    </p>
@endif
