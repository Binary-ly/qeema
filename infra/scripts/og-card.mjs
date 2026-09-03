// SPDX-License-Identifier: Apache-2.0
//
// Rasterise the share card.
//
//     cd e2e && node ../infra/scripts/og-card.mjs
//
// Link previews want a PNG at 1200x630 and will neither render SVG nor load
// webfonts, so the card is authored as a page (infra/brand/og-card.html) and
// photographed here with the same Chromium the e2e suite already installs.
// Run from e2e/ because that is where Playwright is a dependency; this is a
// dev-time tool and the PNG it writes is committed, so the runtime path never
// touches it (constraint C1).
//
// `document.fonts.ready` is awaited before the shot: without it Chromium will
// happily photograph the fallback serif a few milliseconds before the real one
// arrives, and the card ships in the wrong face with nothing reporting it.

import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(path.join(process.cwd(), 'package.json'));
const { chromium } = require('playwright');

const here = path.dirname(fileURLToPath(import.meta.url));
const source = path.resolve(here, '../brand/og-card.html');
const target = path.resolve(here, '../../api/public/og.png');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });

await page.goto(`file://${source}`);
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: target, type: 'png', clip: { x: 0, y: 0, width: 1200, height: 630 } });

await browser.close();

console.log(`wrote ${path.relative(process.cwd(), target)}`);
