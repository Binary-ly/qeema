{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('reporter.offline_title') }}</title>
    @vite(['resources/css/reporter.css'])
</head>
<body class="reporter reporter--offline">
    <main>
        <h1>{{ __('reporter.offline_title') }}</h1>
        <p>{{ __('reporter.offline_body') }}</p>
        <a class="reporter__submit" href="{{ route('reporter') }}">{{ __('reporter.offline_action') }}</a>
    </main>
</body>
</html>
