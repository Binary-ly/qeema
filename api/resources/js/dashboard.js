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

    const theme = {
        accent: colour('--accent', '#38bdf8'),
        muted: colour('--muted', '#94a3b8'),
        border: colour('--border', '#263349'),
        text: colour('--text', '#f1f5f9'),
        high: colour('--scale-high', '#f97316'),
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

    const baseAxis = {
        axisLine: { lineStyle: { color: theme.border } },
        axisLabel: { color: theme.muted, fontSize: 11 },
        splitLine: { lineStyle: { color: theme.border, opacity: 0.4 } },
    }

    function baseOption(overrides) {
        return {
            // The whole chart mirrors in RTL, so the time axis still reads in
            // the direction the surrounding text does.
            grid: { top: 24, right: 16, bottom: 32, left: 56, containLabel: true },
            tooltip: { trigger: 'axis' },
            textStyle: { color: theme.text, fontFamily: 'system-ui, sans-serif' },
            xAxis: { type: 'category', inverse: labels.rtl, ...baseAxis },
            yAxis: { type: 'value', scale: true, position: labels.rtl ? 'right' : 'left', ...baseAxis },
            animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ...overrides,
        }
    }

    function nationalOption() {
        const series = data.national || []

        return baseOption({
            xAxis: { type: 'category', inverse: labels.rtl, data: series.map((p) => p.date), ...baseAxis },
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
            xAxis: { type: 'category', inverse: labels.rtl, data: series.map((p) => p.date), ...baseAxis },
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
