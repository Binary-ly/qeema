{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    One endpoint, documented and runnable.

    Everything here renders server-side and is complete with JavaScript blocked:
    the method, the path, what it does, and what its parameters are. `docs.js`
    adds the two things a static page cannot give — the resolved path with a real
    identifier in it, and a Run button that calls the endpoint and shows the
    actual response.

    The actions are hidden until that script has discovered real values, because
    a Run button that fails on `{countryCode}` teaches the reader nothing.

    Takes: $method, $path, $operation, $base, $anchor, and optional $hero.
--}}
@php
    $verb = strtoupper($method);
    $parameters = $operation['parameters'] ?? [];
@endphp

<article
    class="op{{ ($hero ?? false) ? ' op--hero' : '' }}"
    id="{{ $anchor }}"
    data-method="{{ $verb }}"
    data-path="{{ $path }}"
>
    <header class="op__head">
        <span class="op__method op__method--{{ strtolower($verb) }}">{{ $verb }}</span>
        <code class="op__path">{{ $base }}{{ $path }}</code>
    </header>

    {{-- The same path with a real identifier substituted, filled in by the
         script once it has asked the API what exists. Hidden until then. --}}
    <code class="op__resolved" hidden></code>

    @if (! empty($operation['summary']))
        <p class="op__summary">{{ $operation['summary'] }}</p>
    @endif

    @if (! empty($operation['description']))
        <p class="op__desc">{{ $operation['description'] }}</p>
    @endif

    @if ($parameters !== [])
        <dl class="op__params">
            @foreach ($parameters as $parameter)
                <div class="op__param">
                    <dt><code>{{ $parameter['name'] ?? '' }}</code></dt>
                    <dd>
                        <span class="op__param-in">{{ $parameter['in'] ?? '' }}</span>
                        @if (! empty($parameter['required']))
                            <span class="op__param-req">required</span>
                        @endif
                        @if (! empty($parameter['description']))
                            <span class="op__param-desc">{{ $parameter['description'] }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="op__actions">
        <button type="button" class="op__btn" data-act="copy">Copy as curl</button>

        {{-- Only GET. Running the submission endpoint from a documentation page
             would write a price into the published data, which is not something
             a reader should be able to do by pressing a button labelled "Run". --}}
        @if ($verb === 'GET')
            <button type="button" class="op__btn op__btn--run" data-act="run">Run it</button>
        @endif
    </div>

    <div class="op__result"></div>
</article>
