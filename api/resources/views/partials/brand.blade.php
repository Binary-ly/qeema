{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    The Qeema mark.

    **What it is.** A cradle that is also the pan of a scale, with the basket
    rising out of it as bars of unequal height. Three readings, all of them the
    product: a child is held, a value is weighed, and what is being weighed is a
    basket whose items do not matter equally — which is the whole methodological
    claim of a *child-weighted* index. The bowl also carries the shape of the
    ق that opens قيمة, "value", without pretending to be calligraphy.

    **Why it is drawn here rather than shipped as a file.** Inline SVG inherits
    `currentColor`, so one mark works on the ink masthead and on paper without a
    second asset; it costs no request (C1); and it is in the accessibility tree
    with a real name rather than being a background image.

    **The motion is meaningful, not decorative.** The bowl draws itself, the
    bars rise in sequence, and the whole mark settles like a balance finding
    level. It enacts measuring. It runs once, on load, in CSS — the public pages
    run `script-src 'self'` with no inline script, so an animation that needed
    JavaScript could not run here at all, and one that needed a library would
    cost more than the page.

    Every animation arrives *at* the resting state rather than departing from
    it, so with `prefers-reduced-motion` set — or with the stylesheet still in
    flight — the finished mark is what renders.
--}}
{{-- Clicking the mark used to change the reader's language: it linked to the
     bare dashboard route, and a URL with no `locale` falls back to the
     country's default, so an English reader landed on the Arabic page. A
     masthead mark means "home", never "start again in a language you did not
     choose". `$country` is guarded because this partial is also included from
     `/docs`, which is a `Route::view` and passes no view data at all. --}}
<a class="brand" href="@localised('dashboard', isset($country) ? $country->code : null)" aria-label="{{ config('app.name') }}">
    <svg class="brand__mark" viewBox="0 0 32 32" width="42" height="42" aria-hidden="true" focusable="false">
        <g class="brand__balance">
            {{-- The basket, rising. Unequal on purpose: the index is weighted.
                 Rects rather than round-capped lines, because each bar grows
                 from its own base and `transform-box: fill-box` needs a real
                 fill area to take an origin from — a zero-width line has none
                 and scales from the wrong point. `rx` restores the round cap. --}}
            <g class="brand__bars" fill="var(--q-brand)">
                <rect class="brand__bar" x="8.7" y="9.2" width="2.6" height="9.7" rx="1.3" />
                <rect class="brand__bar" x="12.7" y="5.2" width="2.6" height="15.5" rx="1.3" />
                <rect class="brand__bar" x="16.7" y="7.2" width="2.6" height="13.5" rx="1.3" />
                <rect class="brand__bar" x="20.7" y="10.7" width="2.6" height="8.2" rx="1.3" />
            </g>

            {{-- The cradle. Drawn after the bars so it reads as holding them.
                 `pathLength="1"` normalises the stroke length, so the draw-on
                 animation is exact without anyone measuring the curve. --}}
            <path
                class="brand__cradle"
                d="M5 14 Q16 27 27 14"
                pathLength="1"
                fill="none"
                stroke="currentColor"
                stroke-width="2.4"
                stroke-linecap="round"
            />
        </g>
    </svg>

    <span class="brand__word">
        <span class="brand__name">Qeema</span>
        <span class="brand__native" lang="ar" dir="rtl">قيمة</span>
    </span>
</a>
