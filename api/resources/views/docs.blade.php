{{-- SPDX-License-Identifier: Apache-2.0 --}}
@php
    $spec = is_file(public_path('openapi.json'))
        ? json_decode((string) file_get_contents(public_path('openapi.json')), true)
        : null;

    $base = rtrim($spec['servers'][0]['url'] ?? '', '/');

    // A stable anchor per endpoint. The spec's own operationIds are opaque
    // hashes — fine as identifiers, useless in a URL bar and unstable across a
    // regeneration.
    $anchorFor = static fn (string $method, string $path): string => strtolower($method).'-'
        .trim(preg_replace('/[^a-z0-9]+/i', '-', str_replace(['{', '}'], '', $path)) ?? '', '-');

    // Groups in the order somebody meets them: what exists, what it costs, how
    // to add to it, and whether the thing is up.
    $groupOrder = ['reference', 'index', 'submissions', 'ops'];
    $groupTitles = [
        'reference' => 'What this deployment publishes',
        'index' => 'What a basket costs',
        'submissions' => 'Adding a price',
        'ops' => 'Is it up?',
    ];

    // The one call that answers the question the whole platform exists to
    // answer. Featured at the top and skipped in its own group, so the
    // reference lists each endpoint exactly once.
    $heroPath = '/countries/{countryCode}/index/current';

    $groups = [];

    foreach ($spec['paths'] ?? [] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $groups[$operation['tags'][0] ?? 'other'][] = [
                'method' => $method,
                'path' => $path,
                'operation' => $operation,
                'anchor' => $anchorFor($method, $path),
            ];
        }
    }

    // Anything the spec tags with something unexpected still gets listed.
    $orderedGroups = [];

    foreach ([...$groupOrder, ...array_keys($groups)] as $tag) {
        if (isset($groups[$tag]) && ! isset($orderedGroups[$tag])) {
            $orderedGroups[$tag] = $groups[$tag];
        }
    }

    $hero = null;

    foreach ($orderedGroups as $entries) {
        foreach ($entries as $entry) {
            if ($entry['path'] === $heroPath && strtolower($entry['method']) === 'get') {
                $hero = $entry;
            }
        }
    }
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

    {{-- The link preview, for the reference a developer is sent a link to. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $spec['info']['title'] ?? 'API' }} — API">
    <meta property="og:description" content="No key. No account. No rate tier. {{ Str::limit(strtok($spec['info']['description'] ?? '', "\n"), 120) }}">
    <meta property="og:url" content="{{ url('/docs') }}">
    <meta property="og:locale" content="en">
    <meta property="og:image" content="{{ url('/og.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ config('app.name') }} — API reference">
    <meta name="twitter:card" content="summary_large_image">
    {{-- Styles live in the stylesheet, not in a <style> block here. An inline
         block is exactly what forces `style-src 'unsafe-inline'` back into the
         policy the public pages spent a phase removing. --}}
    @vite(['resources/css/reporter.css', 'resources/js/docs.js'])
</head>
<body class="reporter reporter--docs">
<header class="reporter__header">
    <div>
        @include('partials.brand')
        <h1 class="reporter__title">{{ $spec['info']['title'] ?? 'API' }}</h1>

        {{-- The claim this page exists to make, said at the size it deserves.

             It was a grey clause at the end of a subtitle. "No key, no account,
             no rate tier" is the whole difference between this API and almost
             every other one, it is what makes the Run buttons below possible at
             all, and constraint C6 makes it a promise rather than a current
             configuration. This page's signature is that it is live; the line
             that earns that should not be set in 15px grey. --}}
        <p class="docs__claim">
            <b>No key.</b>
            <b>No account.</b>
            <b>No rate tier.</b>
        </p>

        <p class="reporter__subtitle">Public API reference.</p>
    </div>
    <a class="reporter__home" href="@localised('dashboard')">Dashboard</a>
</header>

<main class="docs">
    @if ($spec === null)
        <p>The specification has not been generated. Run <code>php artisan qeema:openapi</code>.</p>
    @else
        <div class="docs__layout">
            {{-- Ten endpoints in a flat column is a list to scroll rather than a
                 reference to use. --}}
            <nav class="docs__nav" aria-label="Endpoints">
                <ul>
                    @if ($hero !== null)
                        <li><a class="docs__nav-lead" href="#start-here">Start here</a></li>
                    @endif

                    @foreach ($orderedGroups as $tag => $entries)
                        <li class="docs__nav-group">{{ $groupTitles[$tag] ?? ucfirst((string) $tag) }}</li>
                        @foreach ($entries as $entry)
                            @continue($hero !== null && $entry['anchor'] === $hero['anchor'])
                            <li>
                                <a href="#{{ $entry['anchor'] }}">
                                    <span class="docs__nav-method">{{ strtoupper($entry['method']) }}</span>
                                    {{-- Escaped first, then a <wbr> after each
                                         slash: a break opportunity between path
                                         segments, so a long path wraps like a
                                         path rather than mid-word. --}}
                                    <span class="docs__nav-path">{!! str_replace('/', '/<wbr>', e($entry['path'])) !!}</span>
                                </a>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </nav>

            <div class="docs__body">
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

                @if ($hero !== null)
                    {{-- The page opens on a real response rather than on a
                         promise of one. Nothing on this page is a recording:
                         the block below is fetched from this deployment when
                         the page loads, which is only possible because the read
                         API genuinely needs no key (constraint C6). --}}
                    <section class="docs__start" id="start-here">
                        <h2 class="docs__h2">Start here</h2>
                        <p class="docs__lede">
                            One request returns what a child's basket costs in every town this
                            deployment tracks, with the coverage and the estimated share attached
                            to each figure. It runs below, live, with no key and no account.
                        </p>

                        @include('partials.api-operation', [
                            'method' => $hero['method'],
                            'path' => $hero['path'],
                            'operation' => $hero['operation'],
                            'base' => $base,
                            'anchor' => 'start-here-op',
                            'hero' => true,
                        ])
                    </section>
                @endif

                @foreach ($orderedGroups as $tag => $entries)
                    <section class="docs__group">
                        <h2 class="docs__h2">{{ $groupTitles[$tag] ?? ucfirst((string) $tag) }}</h2>

                        @foreach ($entries as $entry)
                            @continue($hero !== null && $entry['anchor'] === $hero['anchor'])
                            @include('partials.api-operation', [
                                'method' => $entry['method'],
                                'path' => $entry['path'],
                                'operation' => $entry['operation'],
                                'base' => $base,
                                'anchor' => $entry['anchor'],
                                'hero' => false,
                            ])
                        @endforeach
                    </section>
                @endforeach

                <section class="docs__group">
                    <h2 class="docs__h2">The whole specification</h2>
                    <p class="docs__lede">
                        Everything on this page is generated from an OpenAPI 3 document, which is
                        itself public. Point a client generator at it.
                    </p>
                    <p><a class="reporter__submit" href="{{ route('openapi') }}">Download openapi.json</a></p>
                </section>
            </div>
        </div>
    @endif
</main>
</body>
</html>
