{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title>{{ __('reporter.offline_title') }}</title>
    @vite(['resources/css/reporter.css'])
</head>
<body class="reporter reporter--offline">
    <main>
        {{-- Inline SVG, so the mark is still here with no network at all —
             which is the one condition this page is written for. --}}
        @include('partials.brand')
        <h1>{{ __('reporter.offline_title') }}</h1>
        <p>{{ __('reporter.offline_body') }}</p>
        <a class="reporter__submit" href="@localised('reporter')">{{ __('reporter.offline_action') }}</a>
    </main>
</body>
</html>
