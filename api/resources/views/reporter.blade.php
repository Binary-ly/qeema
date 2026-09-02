{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover so the layout reaches the edges on notched phones,
         and no user-scalable=no: pinch-zoom is an accessibility requirement. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Matches the masthead ink, so the phone's own chrome continues the page
         rather than framing it in the old palette's navy. --}}
    <meta name="theme-color" content="#0b1f2a">
    <title>{{ __('reporter.title') }}</title>
    <meta name="description" content="{{ __('reporter.subtitle') }}">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    @vite(['resources/css/reporter.css', 'resources/js/reporter.js'])
</head>

{{--
    Styling uses CSS logical properties throughout (inline-start, margin-inline)
    rather than left/right, so the same stylesheet is correct in both
    directions. A mirrored stylesheet would drift out of sync the first time
    someone edited only one of them.
--}}
{{-- Configuration as inert data rather than as an x-data argument. Alpine's
     CSP build evaluates no expressions, so it cannot call `reporter({...})` —
     and a JSON block is not script, so carrying the values this way needs no
     widening of the content-security policy. --}}
<script type="application/json" id="reporter-config">@json($config + ['strings' => __('reporter')])</script>

<body class="reporter" x-data="reporter" x-cloak>

<header class="reporter__header">
    <div class="reporter__masthead">
        @include('partials.brand')
        <h1 class="reporter__title">{{ __('reporter.title') }}</h1>
        <p class="reporter__subtitle">{{ __('reporter.subtitle') }}</p>
    </div>

    <div class="reporter__locale">
        @foreach ($availableLocales as $code)
            <a href="{{ route('reporter', ['locale' => $code]) }}"
               @class(['is-active' => $code === $locale])
               hreflang="{{ $code }}">{{ strtoupper($code) }}</a>
        @endforeach
    </div>

    {{--
        The Fifteen, on the screen where it is an instruction.

        This device is the brand: fifteen bars at their real weights, hollow
        where nobody anywhere can price the item. The dashboard puts it in its
        masthead as a diagnosis. Here it is the job — every hollow bar is one
        the person holding this phone can fill, and when they do, it lights.

        It lived only on the dashboard, which is why this app looked like a form
        that happened to share a logo rather than the same product. It is the
        one element that makes the two pages recognisably one thing, and the
        only one that means something different on each.
    --}}
    <div class="reporter__meter" x-show="hasMeter">
        <div class="fifteen fifteen--ink" role="img" :aria-label="meterLine">
            <template x-for="bar in meterBars" :key="bar.code">
                <span :class="bar.className" :style="bar.style" :title="bar.label"></span>
            </template>
        </div>
        <p class="reporter__meter-label" x-text="meterLine"></p>
    </div>
</header>

{{-- Connectivity is stated plainly and permanently. A reporter needs to know
     their work is safe, not discover later that it vanished. --}}
<div :class="statusClass"
     role="status"
     aria-live="polite">
    <span x-text="statusLabel"></span>

    <template x-if="hasQueued">
        <span class="reporter__queue" x-text="pendingLabel"></span>
    </template>

    <template x-if="hasFailed">
        <span class="reporter__queue is-error" x-text="failedLabel"></span>
    </template>
</div>

<template x-if="flashMessage">
    <p :class="flashClass" x-text="flashMessage" role="alert"></p>
</template>

<template x-if="loadError">
    <p class="reporter__flash is-error">{{ __('reporter.load_error') }}</p>
</template>

<main class="reporter__form" x-show="ready">

    <section class="field field--step">
        <label class="field__label" for="location">{{ __('reporter.location') }}</label>
        <select id="location" class="field__control" x-model="locationSlug">
            {{-- The placeholder used to repeat the label above it word for
                 word, so the field read "Where are you? / Where are you?" and
                 said nothing about what to do. --}}
            <option value="" disabled>{{ __('reporter.location_placeholder') }}</option>
            <template x-for="location in locationOptions" :key="location.slug">
                <option :value="location.slug" x-text="location.optionLabel"></option>
            </template>
        </select>
    </section>

    {{--
        Step two: what.

        Two states rather than one form. Until something is picked this is the
        picker and nothing else; the moment something is picked it collapses to
        a single line, which brings the price field and the save button up into
        the first screen instead of leaving them below a list.
    --}}
    <section class="field field--step">
        <label class="field__label" for="item">{{ __('reporter.item') }}</label>

        <template x-if="hasChosen">
            <div class="chosen">
                <span class="chosen__names">
                    <span class="chosen__name" x-text="chosenLabel"></span>
                    <span class="chosen__alt" x-show="chosenSub" x-text="chosenSub"></span>
                </span>
                <button type="button" class="chosen__change" @click="clearItem" x-text="changeLabel"></button>
            </div>
        </template>

        <template x-if="showPicker">
            <div>
                <input id="item"
                       class="field__control"
                       type="search"
                       inputmode="search"
                       autocomplete="off"
                       x-model="itemQuery"
                       placeholder="{{ __('reporter.item_search') }}">

                {{-- What the platform is missing, said out loud. The dashboard
                     leads on the fact that most of a child's basket has no
                     price anywhere; this is the one screen where somebody can
                     do something about it, and it used to be the one screen
                     that never mentioned it. --}}
                <p class="need-line" :class="needClass" x-text="needLine"></p>

                {{-- A grid, not a scrolling box. The old list scrolled inside a
                     page that also scrolled, which on a phone means one gesture
                     with two possible meanings. --}}
                <ul class="picker">
                    <template x-for="item in filteredItems" :key="item.code">
                        <li>
                            {{-- The item travels to the handler as a data
                                 attribute: CSP event bindings are method
                                 references, so they take no arguments. --}}
                            <button type="button"
                                    :class="item.className"
                                    :data-code="item.code"
                                    @click="selectItem">
                                <span class="picker__name" x-text="item.label"></span>
                                {{-- The other script, when there is one. A
                                     reporter may know the item by its local
                                     name while reading in English, or the
                                     reverse. --}}
                                <span class="picker__alt" x-show="item.sub" x-text="item.sub"></span>
                                <span class="picker__meta">
                                    <span class="picker__need" x-show="item.isNeeded" x-text="needBadge"></span>
                                    <span class="picker__unit" x-text="item.unitText"></span>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>

                <p class="field__hint">{{ __('reporter.item_free_text') }}</p>
            </div>
        </template>
    </section>

    {{-- Step three: how much. The only thing left, and the only field on the
         page given this much room. --}}
    <section class="field field--price field--step">
        <label class="field__label" for="price">
            {{ __('reporter.price') }}
            <span x-text="currencyLabel"></span>
        </label>
        {{-- inputmode="decimal" gives a numeric keypad without the spinner
             controls and scroll-to-change behaviour of type="number". --}}
        <input id="price"
               x-ref="price"
               class="field__control field__control--price"
               type="text"
               inputmode="decimal"
               autocomplete="off"
               x-model="price"
               placeholder="0">

        {{-- What the number is the price of. The quantity decides how this
             price is normalised and it sits behind a disclosure, so without
             this line the reporter is asserting something they cannot see. --}}
        <p class="field__for" x-show="hasPricedFor" x-text="pricedFor"></p>
    </section>

    {{-- Quantity and unit come from the catalogue and are right almost every
         time. They were two more fields between the price and the button. --}}
    <section class="field">
        <button type="button" class="disclosure" @click="toggleDetails" :aria-expanded="showDetails">
            <span x-text="detailsLabel"></span>
        </button>

        <template x-if="showDetails">
            <div class="field--row">
                <div>
                    <label class="field__label" for="quantity">{{ __('reporter.quantity') }}</label>
                    <input id="quantity" class="field__control" type="text" inputmode="decimal" x-model="quantity">
                </div>
                <div>
                    <label class="field__label" for="unit">{{ __('reporter.unit') }}</label>
                    {{-- `:value`, not `x-model`: the field shows the unit
                         written out in the reader's language while `unit` keeps
                         the code, which is what the submission carries. --}}
                    <input id="unit" class="field__control" type="text" :value="unitLabel(unit)" readonly>
                </div>
            </div>
        </template>
    </section>

    {{-- What is still missing, rather than a grey button and silence. --}}
    <p class="reporter__hint" x-show="hasSubmitHint" x-text="submitHint"></p>

    <button type="button"
            class="reporter__submit"
            :disabled="submitDisabled"
            @click="submit"
            x-text="submitLabel"></button>

    <p class="reporter__sent" x-text="sentLine"></p>
    <p class="reporter__id" x-text="reporterLabel"></p>
</main>

{{-- Service worker registration lives in resources/js/reporter.js, not in an
     inline block. An inline <script> would force 'unsafe-inline' into the
     script-src policy for this route, which is most of what the policy is
     worth — and unlike Alpine's need for eval, this one is avoidable. --}}
</body>
</html>
