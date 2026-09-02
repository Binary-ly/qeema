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
    <div>
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

    <section class="field">
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

    <section class="field">
        <label class="field__label" for="item">{{ __('reporter.item') }}</label>
        <input id="item"
               class="field__control"
               type="search"
               inputmode="search"
               autocomplete="off"
               x-model="itemQuery"
               placeholder="{{ __('reporter.item_search') }}">

        <p class="field__hint">{{ __('reporter.item_free_text') }}</p>

        <ul class="item-list" x-show="showItemList">
            <template x-for="item in filteredItems" :key="item.code">
                <li>
                    {{-- The item travels to the handler as a data attribute:
                         CSP event bindings are method references, so they take
                         no arguments. --}}
                    <button type="button"
                            :class="item.className"
                            :data-code="item.code"
                            @click="selectItem">
                        <span class="item-list__name">
                            <span x-text="item.label"></span>
                            {{-- The other script, when there is one. A reporter
                                 may know the item by its local name while
                                 reading the page in English, or the reverse. --}}
                            <span class="item-list__alt" x-show="item.sub" x-text="item.sub"></span>
                        </span>
                        <small x-text="unitLabel(item.unit)"></small>
                    </button>
                </li>
            </template>
        </ul>
    </section>

    <section class="field field--price">
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
    </section>

    <section class="field field--row">
        <div>
            <label class="field__label" for="quantity">{{ __('reporter.quantity') }}</label>
            <input id="quantity" class="field__control" type="text" inputmode="decimal" x-model="quantity">
        </div>
        <div>
            <label class="field__label" for="unit">{{ __('reporter.unit') }}</label>
            {{-- `:value`, not `x-model`: the field shows the unit written out
                 in the reader's language while `unit` keeps the code, which is
                 what the submission carries. Binding the model straight to the
                 input printed the code at the reporter and would have posted a
                 translated word had it been made editable. --}}
            <input id="unit" class="field__control" type="text" :value="unitLabel(unit)" readonly>
        </div>
    </section>

    <button type="button"
            class="reporter__submit"
            :disabled="submitDisabled"
            @click="submit"
            x-text="submitLabel"></button>

    <p class="reporter__id" x-text="reporterLabel"></p>
</main>

{{-- Service worker registration lives in resources/js/reporter.js, not in an
     inline block. An inline <script> would force 'unsafe-inline' into the
     script-src policy for this route, which is most of what the policy is
     worth — and unlike Alpine's need for eval, this one is avoidable. --}}
</body>
</html>
