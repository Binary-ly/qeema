{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('dashboard.title') }}</title>
    @vite(['resources/css/dashboard.css'])
</head>
<body class="dash">
<main id="main" class="dash__main">
    <section class="dash__empty">
        <h1 class="dash__title">{{ __('dashboard.title') }}</h1>
        {{-- No configured country is a real state on first boot, before the
             seed has run. Saying so beats a 500. --}}
        <h2>{{ __('dashboard.no_data') }}</h2>
        <p>{{ __('dashboard.no_data_body') }}</p>
    </section>
</main>
</body>
</html>
