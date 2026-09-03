{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    The Fifteen — the brand device.

    One bar per basket item, its height the item's weight, lit if anybody can
    price it and hollow if nobody can. It is the same shape as the mark in the
    masthead: four bars in a cradle there, the whole basket here, so the logo
    reads as a miniature of the thing the platform measures rather than as a
    decoration bolted on beside it.

    It is live. This is not an illustration of a basket, it is *the* basket on
    the day it is looked at, and it changes the moment a reporter prices
    something nobody had priced.

    Takes: $basket (rows from DashboardData::basketCoverage) and an optional
    $tone of `ink` for use on a dark band.
--}}
@php
    $cells = $basket ?? [];
    // Weights sum to one across fifteen items, so the largest is about a
    // seventh. Bars are floored at 40% of full height and scaled across the
    // remainder: at true scale the lightest item is a two-pixel smudge and the
    // device stops being readable as a row.
    $peak = max(collect($cells)->max('weight') ?: 0.0001, 0.0001);
@endphp

@if (! empty($cells))
    <div class="fifteen{{ isset($tone) && $tone === 'ink' ? ' fifteen--ink' : '' }}" role="img"
         aria-label="{{ $label ?? '' }}">
        @foreach ($cells as $cell)
            <span
                class="fifteen__bar{{ $cell['locations'] === 0 ? ' is-hollow' : '' }}"
                style="--h: {{ round(40 + ($cell['weight'] / $peak) * 60, 1) }}%; --i: {{ $loop->index }}"
                title="{{ $cell['name'] }}{{ $cell['locations'] === 0 ? ' — '.__('dashboard.basket_none') : '' }}"
            ></span>
        @endforeach
    </div>
@endif
