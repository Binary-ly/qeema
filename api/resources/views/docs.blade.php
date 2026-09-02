{{-- SPDX-License-Identifier: Apache-2.0 --}}
@php
    $spec = is_file(public_path('openapi.json'))
        ? json_decode((string) file_get_contents(public_path('openapi.json')), true)
        : null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title>{{ $spec['info']['title'] ?? 'API' }} — API</title>
    {{-- First line of the spec's own description, so the two cannot drift. --}}
    <meta name="description" content="{{ Str::limit(strtok($spec['info']['description'] ?? 'Public API documentation.', "\n"), 155) }}">
    {{-- Styles live in the stylesheet, not in a <style> block here. An inline
         block is exactly what forces `style-src 'unsafe-inline'` back into the
         policy the public pages spent a phase removing. --}}
    @vite(['resources/css/reporter.css'])
</head>
<body class="reporter reporter--docs">
<header class="reporter__header">
    <div>
        @include('partials.brand')
        <h1 class="reporter__title">{{ $spec['info']['title'] ?? 'API' }}</h1>
        <p class="reporter__subtitle">Public API reference — no key, no account, no rate tier.</p>
    </div>
    <a class="reporter__home" href="@localised('dashboard')">Dashboard</a>
</header>
<main class="docs">
    @if ($spec === null)
        <p>The specification has not been generated. Run <code>php artisan qeema:openapi</code>.</p>
    @else
        {{-- The OpenAPI description field is markdown by specification, and
             Swagger-style viewers render it. This page escaped it, so `**bold**`
             and `code` appeared on screen as literal asterisks and backticks.
             Escape first, then promote only `**…**` and `` `…` `` — the input is
             escaped before any tag is introduced, so nothing in the spec can
             inject markup. --}}
        @php
            // Blank lines separate paragraphs; a single newline is source
            // wrapping in the YAML and must not survive into the page. This was
            // rendered inside `white-space: pre-wrap`, which honoured every one
            // of them — so sentences broke at whatever column the spec happened
            // to wrap at, and a `code` span sitting after a wrap arrived with a
            // stray gap before the full stop that followed it.
            $paragraphs = preg_split(
                '/\n\s*\n/',
                trim((string) ($spec['info']['description'] ?? '')),
            ) ?: [];
        @endphp

        <div class="docs__intro">
            @foreach ($paragraphs as $paragraph)
                <p>{!! preg_replace(
                    ['/\*\*(.+?)\*\*/s', '/`([^`]+)`/'],
                    ['<strong>$1</strong>', '<code>$1</code>'],
                    e((string) preg_replace('/\s*\n\s*/', ' ', trim($paragraph)))
                ) !!}</p>
            @endforeach
        </div>

        <p><a class="reporter__submit" href="{{ route('openapi') }}">Download the OpenAPI 3 specification</a></p>

        @foreach ($spec['paths'] as $path => $operations)
            @foreach ($operations as $method => $operation)
                <section class="op">
                    <div>
                        <span class="op__method">{{ strtoupper($method) }}</span>
                        <span class="op__path">{{ rtrim($spec['servers'][0]['url'] ?? '', '/') }}{{ $path }}</span>
                    </div>
                    <p class="op__summary">{{ $operation['summary'] ?? '' }}</p>
                    @if (! empty($operation['description']))
                        <p class="op__desc">{{ $operation['description'] }}</p>
                    @endif
                </section>
            @endforeach
        @endforeach
    @endif
</main>
</body>
</html>
