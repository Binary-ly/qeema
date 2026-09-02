// SPDX-License-Identifier: Apache-2.0

/**
 * Chart hydration for the public dashboard.
 *
 * Everything here is an enhancement. The page is complete and every number on
 * it is correct before this file runs — each chart container already holds a
 * text statement of its latest value. If this never loads, or ECharts fails,
 * the reader loses the shape of the trend but not the facts.
 *
 * Two things keep the bundle honest:
 *
 * - ECharts is pulled in through `./dashboard/echarts.js`, which imports only
 *   the line chart and the three components in use. Importing the barrels
 *   instead measured 84 kB gzipped of unused chart types plus a 245 kB GeoJSON
 *   parser, to draw two line charts.
 * - That module is loaded **dynamically**, behind an IntersectionObserver, so
 *   chart code is not fetched until a chart nears the viewport and never
 *   competes with first paint. That is what makes the Lighthouse performance
 *   target reachable on a throttled mid-tier phone.
 */

// Country switching. Lives here rather than in an onchange attribute so the
// page can be served under a Content-Security-Policy that forbids inline
// script — which is the policy worth having.
const picker = document.getElementById('country-picker')

if (picker) {
    picker.addEventListener('change', () => {
        const locale = picker.dataset.locale ?? ''
        window.location.search = `?country=${encodeURIComponent(picker.value)}&locale=${encodeURIComponent(locale)}`
    })
}

const chartsEl = document.getElementById('dash-charts')
const labelsEl = document.getElementById('dash-labels')

if (chartsEl && labelsEl) {
    const data = JSON.parse(chartsEl.textContent)
    const labels = JSON.parse(labelsEl.textContent)

    // Read the palette from CSS rather than duplicating hex values here. One
    // place to change the colours, and dark/light mode comes along for free.
    const css = getComputedStyle(document.documentElement)
    const colour = (name, fallback) => css.getPropertyValue(name).trim() || fallback

    // Names must track tokens.css. When the palette was renamed to the `--q-`
    // prefix these kept reading the old names, so every fallback below applied
    // and the charts quietly rendered in the previous dark theme on a white
    // page — the one part of the design system that cannot be seen to be wrong
    // by reading the stylesheet.
    const theme = {
        accent: colour('--q-brand-deep', '#0b6e92'),
        muted: colour('--q-muted', '#55666f'),
        border: colour('--q-rule', '#dfe7ec'),
        text: colour('--q-ink', '#0b1f2a'),
        high: colour('--q-scale-high', '#0a5a7d'),
        font: colour('--q-font-body', 'system-ui, sans-serif'),
    }

    let echartsModule = null

    /**
     * Load the trimmed ECharts bundle once.
     *
     * The dynamic import targets our own module, which statically imports only
     * the pieces in use — see resources/js/dashboard/echarts.js for why that
     * distinction is what keeps the chunk small.
     */
    async function loadECharts() {
        if (!echartsModule) {
            echartsModule = (await import('./dashboard/echarts.js')).default
        }

        return echartsModule
    }

    // Statistical-chart furniture: no tick marks, one hairline baseline, and
    // horizontal rules only. Boxed axes and vertical gridlines are decoration
    // that a reader has to look past to reach the line.
    const baseAxis = {
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: theme.muted, fontSize: 11, fontFamily: theme.font },
        splitLine: { lineStyle: { color: theme.border } },
    }

    // Dates do not need gridlines. Rules on both axes make a cage, and the one
    // that helps a reader compare values is the horizontal set.
    const categoryAxis = { ...baseAxis, splitLine: { show: false } }

    function baseOption(overrides) {
        return {
            // The whole chart mirrors in RTL, so the time axis still reads in
            // the direction the surrounding text does.
            grid: { top: 24, right: 16, bottom: 32, left: 56, containLabel: true },
            tooltip: {
                trigger: 'axis',
                backgroundColor: '#fff',
                borderColor: theme.border,
                borderWidth: 1,
                textStyle: { color: theme.text, fontFamily: theme.font, fontSize: 12 },
                // A thin guide rather than the default shadow band, which on a
                // white page covers the data it is pointing at.
                axisPointer: { type: 'line', lineStyle: { color: theme.border, width: 1 } },
            },
            textStyle: { color: theme.text, fontFamily: theme.font },
            xAxis: { type: 'category', inverse: labels.rtl, ...categoryAxis },
            yAxis: {
                type: 'value',
                scale: true,
                position: labels.rtl ? 'right' : 'left',
                ...baseAxis,
                // The value axis keeps its rules; the category axis does not
                // need them, and two sets of gridlines make a cage.
                splitLine: { lineStyle: { color: theme.border } },
            },
            animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ...overrides,
        }
    }

    function nationalOption() {
        const series = data.national || []

        return baseOption({
            xAxis: { type: 'category', inverse: labels.rtl, data: series.map((p) => p.date), ...categoryAxis },
            series: [
                {
                    name: labels.cost,
                    type: 'line',
                    smooth: true,
                    showSymbol: false,
                    data: series.map((p) => p.cost),
                    lineStyle: { width: 2.5, color: theme.accent },
                    areaStyle: { color: theme.accent, opacity: 0.12 },
                },
            ],
        })
    }

    function fxOption() {
        const series = data.fx || []

        return baseOption({
            legend: { data: [labels.official, labels.parallel], textStyle: { color: theme.muted } },
            xAxis: { type: 'category', inverse: labels.rtl, data: series.map((p) => p.date), ...categoryAxis },
            series: [
                {
                    name: labels.official,
                    type: 'line',
                    showSymbol: false,
                    // connectNulls stays false on purpose: a gap in the rate
                    // history is real information, and bridging it would draw a
                    // line through days nobody measured.
                    connectNulls: false,
                    data: series.map((p) => p.official),
                    lineStyle: { width: 2, color: theme.accent },
                },
                {
                    name: labels.parallel,
                    type: 'line',
                    showSymbol: false,
                    connectNulls: false,
                    data: series.map((p) => p.parallel),
                    lineStyle: { width: 2, color: theme.high },
                },
            ],
        })
    }

    async function render(el, option) {
        try {
            const core = await loadECharts()

            // Clear the server-rendered fallback only once the chart is about
            // to replace it, never before. A failed import must not leave an
            // empty box where a readable number used to be.
            el.textContent = ''

            const chart = core.init(el, null, { renderer: 'svg' })
            chart.setOption(option)

            const resize = () => chart.resize()
            window.addEventListener('resize', resize, { passive: true })
        } catch (error) {
            // The fallback text is still in place; leave it there.
            console.warn('Charts unavailable, showing text fallback.', error)
        }
    }

    const targets = [
        ['chart-national', nationalOption, () => (data.national || []).length > 1],
        ['chart-fx', fxOption, () => (data.fx || []).length > 1],
    ]

    for (const [id, optionFor, hasEnoughData] of targets) {
        const el = document.getElementById(id)

        // One point does not make a line. Below two, the text fallback already
        // says everything the chart could.
        if (!el || !hasEnoughData()) continue

        if (!('IntersectionObserver' in window)) {
            render(el, optionFor())
            continue
        }

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue

                    observer.disconnect()
                    render(el, optionFor())
                }
            },
            // Start fetching slightly before the chart scrolls into view, so it
            // is usually ready by the time it is looked at.
            { rootMargin: '200px' },
        )

        observer.observe(el)
    }
}

/**
 * Reveal and count-up.
 *
 * Motion is opt-in from JavaScript, never from CSS. The hidden start state is
 * applied by adding `js-reveal` to the body here — so a reader with no
 * JavaScript, a failed bundle or a slow connection gets the finished page
 * rather than a blank one, which is the failure mode of every CSS-only reveal
 * that assumes its script will arrive.
 *
 * It is also the whole feature behind `prefers-reduced-motion`: if the reader
 * has asked for less movement, nothing below runs at all and the page is simply
 * complete from the first paint.
 */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

if (!reduceMotion && 'IntersectionObserver' in window) {
    document.body.classList.add('js-reveal')

    /** Count a figure up to the value already in the markup. */
    const countUp = (el) => {
        const target = Number(el.dataset.count)

        if (!Number.isFinite(target)) {
            return
        }

        const duration = 900
        const started = performance.now()

        // A stuck counter is a wrong number, not a missing animation.
        // requestAnimationFrame is throttled hard in a background tab and can
        // stop firing altogether, which leaves the figure frozen at whatever it
        // had reached — "1" where the page means "5". This lands the true value
        // on a timer regardless of whether the frames ever arrive.
        const settle = setTimeout(() => {
            el.textContent = String(target)
        }, duration + 250)

        const step = (now) => {
            const t = Math.min((now - started) / duration, 1)
            // Ease out cubic: fast enough to feel immediate, settling rather
            // than stopping.
            const eased = 1 - Math.pow(1 - t, 3)
            el.textContent = String(Math.round(target * eased))

            if (t < 1) {
                requestAnimationFrame(step)
            } else {
                // Land on the exact markup value rather than on rounding.
                clearTimeout(settle)
                el.textContent = String(target)
            }
        }

        requestAnimationFrame(step)
    }

    const targets = [...document.querySelectorAll('.dash__headline, .dash__panel, .dash__rail')]

    const reveal = (el) => {
        if (el.classList.contains('is-revealed')) {
            return
        }

        el.classList.add('is-revealed')
        el.querySelectorAll('[data-count]').forEach(countUp)
    }

    const revealer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue
                }

                reveal(entry.target)
                revealer.unobserve(entry.target)
            }
        },
        // Fires a little before the section is fully on screen, so the movement
        // has finished by the time it is being read.
        { rootMargin: '0px 0px -10% 0px', threshold: 0.08 },
    )

    targets.forEach((el) => revealer.observe(el))

    /*
     * Two backstops, because the cost of this animation misfiring is not a
     * missing flourish — it is a section of a public data page that stays at
     * `opacity: 0` for ever. That happened: an observer set up before layout
     * had settled reported several sections as not intersecting, and they were
     * never looked at again, so most of the page was invisible while the markup
     * and the CSS were both perfectly correct.
     *
     * Anything already on screen is revealed immediately rather than waiting to
     * be told it is on screen...
     */
    const onScreen = (el) => {
        const box = el.getBoundingClientRect()

        return box.top < window.innerHeight && box.bottom > 0
    }

    targets.filter(onScreen).forEach((el) => {
        reveal(el)
        revealer.unobserve(el)
    })

    // ...and everything is revealed unconditionally shortly afterwards, so no
    // arrangement of scroll position, layout timing or observer behaviour can
    // leave content hidden. A reader who never scrolls still sees the page.
    setTimeout(() => targets.forEach(reveal), 2500)
}
