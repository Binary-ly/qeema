{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover so the layout reaches the edges on notched phones,
         and no user-scalable=no: pinch-zoom is an accessibility requirement. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>{{ __('reporter.title') }}</title>

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
<body class="reporter" x-data="reporter(@js($config))" x-cloak>

<header class="reporter__header">
    <div>
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
<div class="reporter__status"
     :class="online ? 'is-online' : 'is-offline'"
     role="status"
     aria-live="polite">
    <span x-text="online
        ? @js(__('reporter.status_online'))
        : @js(__('reporter.status_offline'))"></span>

    <template x-if="queue.pending > 0 || queue.syncing > 0">
        <span class="reporter__queue"
              x-text="@js(__('reporter.queue_pending')).replace(':count', queue.pending + queue.syncing)"></span>
    </template>

    <template x-if="queue.failed > 0">
        <span class="reporter__queue is-error"
              x-text="@js(__('reporter.queue_failed')).replace(':count', queue.failed)"></span>
    </template>
</div>

<template x-if="flashMessage">
    <p class="reporter__flash" :class="'is-' + flashKind" x-text="flashMessage" role="alert"></p>
</template>

<template x-if="loadError">
    <p class="reporter__flash is-error">{{ __('reporter.load_error') }}</p>
</template>

<main class="reporter__form" x-show="ready">

    <section class="field">
        <label class="field__label" for="location">{{ __('reporter.location') }}</label>
        <select id="location" class="field__control" x-model="locationSlug">
            <option value="" disabled>{{ __('reporter.location') }}</option>
            <template x-for="location in locations" :key="location.slug">
                <option :value="location.slug"
                        x-text="location.name_local || location.name"></option>
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
               :placeholder="@js(__('reporter.item_search'))">

        <p class="field__hint">{{ __('reporter.item_free_text') }}</p>

        <ul class="item-list" x-show="itemQuery.length > 0 || itemCode === ''">
            <template x-for="item in filteredItems" :key="item.code">
                <li>
                    <button type="button"
                            class="item-list__button"
                            :class="{ 'is-selected': item.code === itemCode }"
                            @click="selectItem(item)">
                        <span x-text="item.name_local || item.name_en"></span>
                        <small x-text="item.unit"></small>
                    </button>
                </li>
            </template>
        </ul>
    </section>

    <section class="field field--price">
        <label class="field__label" for="price">
            {{ __('reporter.price') }}
            <span x-text="country?.currency?.symbol || country?.currency?.code"></span>
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
            <input id="unit" class="field__control" type="text" x-model="unit" readonly>
        </div>
    </section>

    <button type="button"
            class="reporter__submit"
            :disabled="!canSubmit"
            @click="submit()"
            x-text="busy ? @js(__('reporter.saving')) : @js(__('reporter.submit'))"></button>

    <p class="reporter__id" x-text="@js(__('reporter.reporter_id')).replace(':id', reporterId)"></p>
</main>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' });
        });
    }
</script>
</body>
</html>
