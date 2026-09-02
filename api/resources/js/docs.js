// SPDX-License-Identifier: Apache-2.0

/**
 * The API reference, made live.
 *
 * This page listed ten endpoints as a method, a path and a sentence. Everything
 * a person actually needs before writing a line of code — what comes back, what
 * the parameters are, whether it works at all — was missing, and the only way to
 * find out was to leave the page.
 *
 * The read API needs no key, no account and no rate tier (constraint C6), which
 * is a rarer thing than it sounds and makes something possible here that most
 * API references cannot do: every GET on this page can be *run*, against the
 * real deployment, and show the real response. Nothing is mocked and nothing is
 * a recording. If an endpoint is broken, this page is broken in the same way,
 * which is the honest outcome.
 *
 * Path parameters are discovered from the API itself rather than written into
 * the page — partly because constraint C3 forbids a country code in application
 * source, and partly because an example that starts by asking the API what
 * exists is a better demonstration than one with a value baked in.
 *
 * Progressive enhancement throughout: the page is a complete, readable
 * reference with this file blocked. Everything below only ever adds.
 */

/** Where the API lives, from the spec's own `servers` entry. */
const BASE = '/api/v1';

/** Real values for `{countryCode}`, `{locationSlug}` and `{date}`. */
const sample = {
    countryCode: null,
    locationSlug: null,
    date: null,
};

/**
 * Ask the API what exists, so every example runs against real data.
 *
 * Two requests: the country list, then that country's current index — which
 * carries a location slug and a snapshot date, so one call resolves both of the
 * remaining placeholders.
 */
async function discover() {
    const countries = await getJson(`${BASE}/countries`);
    sample.countryCode = countries?.data?.[0]?.code ?? null;

    if (! sample.countryCode) {
        return;
    }

    const current = await getJson(`${BASE}/countries/${sample.countryCode}/index/current`);
    const first = current?.data?.[0];

    sample.locationSlug = first?.location?.slug ?? null;
    sample.date = first?.date ?? null;
}

async function getJson(url) {
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });

        return response.ok ? await response.json() : null;
    } catch {
        // Discovery is an enhancement. A failure here leaves the placeholders
        // in place and the page still documents the API correctly.
        return null;
    }
}

/**
 * Substitute discovered values into a path template.
 *
 * Returns null when something is still a placeholder, which is what disables
 * the Run button rather than letting it fire a request that cannot work.
 */
function resolvePath(template) {
    const filled = template
        .replace('{countryCode}', sample.countryCode ?? '{countryCode}')
        .replace('{locationSlug}', sample.locationSlug ?? '{locationSlug}')
        .replace('{date}', sample.date ?? '{date}');

    return filled.includes('{') ? null : filled;
}

/** Escape first, then colour. Nothing user-supplied ever becomes markup. */
function highlight(json) {
    const escaped = json
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped.replace(
        /("(?:\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)/g,
        (match) => {
            let token = 'jsonview__n';

            if (/^"/.test(match)) {
                token = /:$/.test(match) ? 'jsonview__k' : 'jsonview__s';
            } else if (/^(true|false|null)$/.test(match)) {
                token = 'jsonview__b';
            }

            return `<span class="${token}">${match}</span>`;
        },
    );
}

/**
 * Render a response body, clipped.
 *
 * A country's current index is sixteen locations with their basket items — a
 * few hundred lines. Printing all of it buries the shape of the thing, which is
 * the only reason anyone is reading it here rather than fetching it.
 */
const MAX_LINES = 34;

function renderBody(data) {
    const text = JSON.stringify(data, null, 2);
    const lines = text.split('\n');

    if (lines.length <= MAX_LINES) {
        return highlight(text);
    }

    const shown = lines.slice(0, MAX_LINES).join('\n');
    const rest = lines.length - MAX_LINES;

    return `${highlight(shown)}\n<span class="jsonview__more">… ${rest} more lines — open the URL for the whole response</span>`;
}

function bytes(size) {
    return size < 1024 ? `${size} B` : `${(size / 1024).toFixed(1)} kB`;
}

/**
 * Run one endpoint and show what came back.
 *
 * Status, elapsed time and size are reported alongside the body, because "it
 * returns JSON" is not the useful part — "it answered in 40 ms without a key"
 * is.
 */
async function run(article) {
    const method = article.dataset.method;
    const path = resolvePath(article.dataset.path);
    const result = article.querySelector('.op__result');

    if (! result || method !== 'GET') {
        return;
    }

    const url = `${BASE}${path ?? article.dataset.path}`;

    result.classList.add('is-open');
    result.innerHTML = '<p class="op__status">…</p>';

    if (path === null) {
        result.innerHTML = '<p class="op__status is-error">No sample data available on this deployment.</p>';

        return;
    }

    const started = performance.now();

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const text = await response.text();
        const elapsed = Math.round(performance.now() - started);

        let body;

        try {
            body = renderBody(JSON.parse(text));
        } catch {
            // CSV export and anything else that is not JSON.
            body = highlight(text.split('\n').slice(0, MAX_LINES).join('\n'));
        }

        const state = response.ok ? '' : ' is-error';

        result.innerHTML =
            `<p class="op__status${state}">${response.status} · ${elapsed} ms · ${bytes(new Blob([text]).size)} · no key</p>` +
            `<pre class="jsonview"><code>${body}</code></pre>`;
    } catch (error) {
        result.innerHTML = `<p class="op__status is-error">${String(error?.message ?? error)}</p>`;
    }
}

/** The request as a reader would paste it into a terminal. */
function curlFor(article) {
    const path = resolvePath(article.dataset.path) ?? article.dataset.path;
    const url = `${window.location.origin}${BASE}${path}`;

    return article.dataset.method === 'GET'
        ? `curl -s '${url}'`
        : `curl -s -X ${article.dataset.method} '${url}' \\\n  -H 'Content-Type: application/json' \\\n  -d '{}'`;
}

async function copy(article, button) {
    const original = button.dataset.label ?? button.textContent;
    button.dataset.label = original;

    try {
        await navigator.clipboard.writeText(curlFor(article));
        button.textContent = button.dataset.done ?? 'Copied';
    } catch {
        button.textContent = 'Press ⌘C';
    }

    setTimeout(() => {
        button.textContent = original;
    }, 1600);
}

/**
 * Show the resolved path beside the documented one.
 *
 * The heading keeps `{countryCode}` — that is what the endpoint is — and this
 * adds the value the Run button will actually use, so nobody has to guess what
 * a real one looks like.
 */
function showResolved() {
    document.querySelectorAll('.op').forEach((article) => {
        const resolved = resolvePath(article.dataset.path);
        const slot = article.querySelector('.op__resolved');

        if (! slot || resolved === null || resolved === article.dataset.path) {
            return;
        }

        slot.textContent = `${BASE}${resolved}`;
        slot.hidden = false;
    });
}

function wire() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-act]');

        if (! button) {
            return;
        }

        const article = button.closest('.op');

        if (! article) {
            return;
        }

        if (button.dataset.act === 'run') {
            run(article);
        } else if (button.dataset.act === 'copy') {
            copy(article, button);
        }
    });
}

async function start() {
    wire();

    // Every Run button is inert until the placeholders resolve, so they are
    // revealed rather than disabled — a button that does nothing when pressed
    // is worse than one that is not there yet.
    await discover();

    if (sample.countryCode) {
        document.querySelectorAll('.op__actions').forEach((el) => el.classList.add('is-ready'));
        showResolved();
    }

    // One real response on arrival, without anyone pressing anything. The
    // claim this page makes is that the data is open; showing it is a shorter
    // argument than saying it.
    const hero = document.querySelector('.op--hero');

    if (hero && sample.countryCode) {
        run(hero);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
