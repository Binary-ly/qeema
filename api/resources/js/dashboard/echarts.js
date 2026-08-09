// SPDX-License-Identifier: Apache-2.0

/**
 * The only ECharts the dashboard actually uses.
 *
 * Imports here are **static and named** on purpose. A dynamic
 * `import('echarts/charts')` looks equivalent but is not: it loads the barrel
 * whole, because a namespace import gives the bundler no way to know which
 * members are live. Measured on this page, that cost 84 kB gzipped of chart
 * types and dragged in a 245 kB GeoJSON parser for two line charts.
 *
 * Named imports let Rollup drop everything untouched. Laziness is preserved by
 * dynamically importing *this module*, not the library barrels.
 */

import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { SVGRenderer } from 'echarts/renderers'

// SVG rather than canvas: it stays sharp at any zoom, prints correctly, and
// keeps the chart in the DOM where the page's own styling reaches it.
echarts.use([LineChart, GridComponent, TooltipComponent, LegendComponent, SVGRenderer])

export default echarts
