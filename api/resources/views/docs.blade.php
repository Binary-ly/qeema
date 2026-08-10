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
    <title>{{ $spec['info']['title'] ?? 'API' }} — API</title>
    {{-- First line of the spec's own description, so the two cannot drift. --}}
    <meta name="description" content="{{ Str::limit(strtok($spec['info']['description'] ?? 'Public API documentation.', "\n"), 155) }}">
    @vite(['resources/css/reporter.css'])
    <style>
        .docs { max-width: 60rem; margin-inline: auto; }
        .docs__intro { white-space: pre-wrap; color: var(--muted); font-size: 0.9375rem; }
        .op { background: var(--surface); border-radius: var(--radius); padding: 0.875rem; margin-block: 0.5rem; }
        .op__method { font-weight: 700; color: var(--accent); font-family: ui-monospace, monospace; }
        .op__path { font-family: ui-monospace, monospace; }
        .op__summary { color: var(--muted); font-size: 0.9375rem; margin: 0.375rem 0 0; }
        .op__desc { font-size: 0.875rem; margin: 0.5rem 0 0; }
    </style>
</head>
<body class="reporter">
<main class="docs">
    <h1 class="reporter__title">{{ $spec['info']['title'] ?? 'API' }}</h1>

    @if ($spec === null)
        <p>The specification has not been generated. Run <code>php artisan qeema:openapi</code>.</p>
    @else
        <p class="docs__intro">{{ $spec['info']['description'] ?? '' }}</p>

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
