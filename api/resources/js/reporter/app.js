// SPDX-License-Identifier: Apache-2.0

/**
 * The reporter submission flow.
 *
 * Plain Alpine rather than Livewire, deliberately. Livewire round-trips to the
 * server for every interaction, which is exactly what a reporter standing in a
 * market with no signal cannot do. Everything here works with the network
 * unplugged; the server is only ever contacted to drain the outbox.
 *
 * The target is under 30 seconds per submission, which is why the flow is three
 * taps and one number rather than a form with a submit button at the bottom.
 */

import { counts, enqueue, flush, requestBackgroundSync, reporterRef } from './queue.js';

/**
 * Configuration and translated strings, read from a JSON block in the page.
 *
 * Not passed as an `x-data` argument any more. Alpine's CSP build evaluates no
 * expressions at all, so `x-data="reporter({...})"` is a call it cannot make —
 * and the whole point of that build is that the page needs no `unsafe-eval`.
 * A `<script type="application/json">` element is inert data rather than script,
 * so it carries the same values without widening the policy.
 */
function pageConfig() {
    const element = document.getElementById('reporter-config');

    return element ? JSON.parse(element.textContent) : {};
}

export default function reporter() {
    const config = pageConfig();
    const strings = config.strings ?? {};

    /** Substitutes `:name` placeholders, the same convention the PHP side uses. */
    const line = (key, values = {}) =>
        Object.entries(values).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            strings[key] ?? '',
        );

    return {
        // --- catalogue, cached for offline use ---
        country: config.country ?? null,
        locations: [],
        items: [],
        // The catalogue ships a name and a local name for every unit and this
        // app was throwing both away, printing the bare code instead — so an
        // Arabic reader picking a tin of formula was told its unit was "pack",
        // in Latin script, in the middle of a right-to-left form.
        units: [],
        ready: false,
        loadError: null,

        // Basket item codes that no location can price, newest known list from
        // the server-rendered config. A hint, not data: it ships in the cached
        // shell, so offline it may be a week old, which is fine for a nudge.
        needs: config.needs ?? [],
        needsCount: config.needsCount ?? 0,
        basketCount: config.basketCount ?? 0,

        // The basket as fifteen bars — the mark from the masthead, carrying
        // real data. See `meterBars` for why this app lights one.
        meter: config.meter ?? [],

        // Item codes this device has priced. Kept on the device because that is
        // where the claim belongs: the reporter's job is done when the entry is
        // stored locally, which is the same rule the queue works by.
        filled: JSON.parse(localStorage.getItem('qeema.filled') ?? '[]'),

        // --- what the reporter is entering ---
        locationSlug: localStorage.getItem('qeema.location') ?? '',
        itemCode: '',
        itemQuery: '',
        price: '',
        unit: '',
        quantity: 1,

        // Quantity and unit are correct from the catalogue for almost every
        // report, and were two always-visible fields between the price and the
        // save button. Folded away until someone actually needs them.
        showDetails: false,

        // What this device has contributed. Unpaid work with no account and no
        // feedback is work that stops; a running count is the smallest honest
        // acknowledgement the app can give.
        sent: Number(localStorage.getItem('qeema.sent') ?? 0),

        // --- connectivity and queue state ---
        online: navigator.onLine,
        queue: { pending: 0, syncing: 0, synced: 0, failed: 0 },
        flashMessage: null,
        flashKind: 'success',
        busy: false,

        async init() {
            await this.loadCatalogue();
            await this.refreshCounts();

            // Three separate triggers, because no single one is reliable:
            // 'online' misses the case where the app was closed when
            // connectivity returned; visibilitychange catches the reporter
            // coming back to the app; Background Sync covers Chromium when the
            // app is not open at all. iOS Safari has none of the last, which is
            // why the first two are not optional.
            window.addEventListener('online', () => {
                this.online = true;
                this.sync();
            });
            window.addEventListener('offline', () => {
                this.online = false;
            });
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && navigator.onLine) {
                    this.sync();
                }
            });

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.addEventListener('message', (event) => {
                    if (event.data?.type === 'qeema:flush-outbox') {
                        this.sync();
                    }
                });
            }

            if (this.online) {
                this.sync();
            }
        },

        /**
         * Load locations and items.
         *
         * The service worker serves this from cache first, so a reporter who
         * opens the app with no signal still gets a working picker rather than
         * an empty screen.
         */
        async loadCatalogue() {
            try {
                const response = await fetch(config.bootstrapUrl, { headers: { Accept: 'application/json' } });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                this.country = data.country;
                this.locations = data.locations ?? [];
                this.items = data.items ?? [];
                this.units = data.units ?? [];
                this.ready = true;

                localStorage.setItem('qeema.catalogue', JSON.stringify(data));
            } catch (error) {
                // Fall back to the last snapshot we successfully stored. Being
                // unable to reach the network is the expected condition, not an
                // error worth showing the reporter.
                const cached = localStorage.getItem('qeema.catalogue');

                if (cached) {
                    const data = JSON.parse(cached);
                    this.country = data.country;
                    this.locations = data.locations ?? [];
                    this.items = data.items ?? [];
                    this.units = data.units ?? [];
                    this.ready = true;
                    return;
                }

                this.loadError = String(error?.message ?? error);
            }
        },

        /*
        |----------------------------------------------------------------------
        | Everything the template used to work out for itself
        |----------------------------------------------------------------------
        |
        | Under the CSP build a template may reference a property or call a
        | method by name, and nothing else — no ternaries, no `||`, no string
        | concatenation. So every derived value a view needs is computed here.
        |
        | This is more code and better placed: the reasoning is now next to the
        | state it depends on rather than spread across markup, and it is
        | reachable from a test.
        */

        get statusLabel() {
            return this.online ? line('status_online') : line('status_offline');
        },

        get statusClass() {
            return this.online ? 'reporter__status is-online' : 'reporter__status is-offline';
        },

        get hasQueued() {
            return this.queue.pending > 0 || this.queue.syncing > 0;
        },

        get hasFailed() {
            return this.queue.failed > 0;
        },

        get pendingLabel() {
            return line('queue_pending', { count: this.queue.pending + this.queue.syncing });
        },

        get failedLabel() {
            return line('queue_failed', { count: this.queue.failed });
        },

        get flashClass() {
            return `reporter__flash is-${this.flashKind}`;
        },

        /**
         * The currency, written the way the rest of the page is written.
         *
         * This always preferred the symbol, so the English form was labelled
         * "PRICE د.ل" — an Arabic symbol set into a left-to-right label, while
         * the dashboard called the same currency LYD two pages away. The symbol
         * is right at home in the local script and is a direction change and an
         * unknown glyph outside it.
         */
        get currencyLabel() {
            const currency = this.country?.currency;

            if (! currency) {
                return '';
            }

            return (this.inLocalScript ? currency.symbol : currency.code) || currency.code || currency.symbol || '';
        },

        get submitLabel() {
            return this.busy ? line('saving') : line('submit');
        },

        get submitDisabled() {
            return !this.canSubmit;
        },

        get reporterLabel() {
            return line('reporter_id', { id: this.reporterId });
        },

        /*
        |----------------------------------------------------------------------
        | Choosing an item
        |----------------------------------------------------------------------
        |
        | The picker used to be a search box above a 180px box that scrolled
        | inside a page that also scrolled — a trap on a phone — and it pushed
        | the price field, the only thing the reporter came to fill in, below
        | the fold. It is now a grid that the page scrolls past, and it folds
        | to a single line the moment something is picked.
        */

        get hasChosen() {
            return this.itemCode !== '';
        },

        get showPicker() {
            return this.itemCode === '';
        },

        get chosenLabel() {
            const item = this.selectedItem;

            return item ? this.naming(item, 'name_local', 'name_en').label : '';
        },

        get chosenSub() {
            const item = this.selectedItem;

            return item ? this.naming(item, 'name_local', 'name_en').sub : '';
        },

        /**
         * The fifteen bars, and which of them are lit.
         *
         * This is the same device the dashboard puts in its masthead, and it
         * means something sharper here. On the dashboard a hollow bar is a
         * diagnosis — a thing a child needs that nothing in this deployment can
         * price. On this screen it is the task: the hollow bars are the ones
         * the person holding the phone can fill, and filling one is the whole
         * reason the crowdsourced layer exists.
         *
         * Three states, because two would lie. `is-hollow` is nobody has priced
         * it; `is-filled` is you just did, on this device, and it lights. A
         * plain bar is priced somewhere already. A filled bar does not claim
         * the figure is published — only that this device has sent one, which
         * is exactly what the reporter did.
         */
        get meterBars() {
            return this.meter.map((bar) => {
                const mine = this.filled.includes(bar.code);
                const state = mine ? ' is-filled' : (bar.unpriced ? ' is-hollow' : '');

                return {
                    ...bar,
                    className: `fifteen__bar${state}`,
                    style: `--h: ${bar.height}%`,
                };
            });
        },

        get hasMeter() {
            return this.meter.length > 0;
        },

        get meterLine() {
            return this.filled.length > 0
                ? line('meter_filled', { count: this.filled.length })
                : line('meter_label');
        },

        get hasNeeds() {
            return this.needsCount > 0;
        },

        get needLine() {
            return this.needsCount > 0
                ? line('need_headline', { count: this.needsCount, total: this.basketCount })
                : line('need_none');
        },

        get needClass() {
            // Only the modifier — the element carries `need-line` itself.
            // Quiet when nothing is missing, so the line is not permanent
            // alarm furniture.
            return this.needsCount > 0 ? 'is-wanted' : '';
        },

        get needBadge() {
            return line('need_badge');
        },

        get pickLabel() {
            return line('pick_item');
        },

        get changeLabel() {
            return line('change_item');
        },

        get detailsLabel() {
            return line('details');
        },

        get sentLine() {
            return line('sent_total', { count: this.sent });
        },

        /**
         * The one thing still missing, named.
         *
         * The save button disables itself until three things are true and said
         * which one was outstanding for none of them, so a first-time reporter
         * met a grey button and no way to find out why. Ordered the way the
         * form is, so the hint always points at the field furthest up the page
         * that still needs something.
         */
        /**
         * What the number being typed is the price *of*.
         *
         * The quantity decides how the price is normalised, and it lived in a
         * field folded away behind a disclosure — so the reporter entered a
         * number without being told what it was the price of, and the app had
         * an opinion they never saw. Stating it under the input costs one quiet
         * line and removes the ambiguity at the point it actually exists.
         *
         * Empty for a free-text item: no catalogue entry, no unit, and inventing
         * one would be the app asserting something it does not know.
         */
        get pricedFor() {
            if (this.unit === '') {
                return '';
            }

            const quantity = Number(this.quantity);
            const shown = Number.isFinite(quantity) ? String(Number(quantity.toFixed(3))) : String(this.quantity);

            return line('priced_for', { quantity: shown, unit: this.unitLabel(this.unit) });
        },

        get hasPricedFor() {
            return this.pricedFor !== '';
        },

        get submitHint() {
            if (this.locationSlug === '') {
                return line('hint_location');
            }

            if (this.itemCode === '' && this.itemQuery.trim() === '') {
                return line('hint_item');
            }

            if (! (Number(this.price) > 0)) {
                return line('hint_price');
            }

            return '';
        },

        get hasSubmitHint() {
            return this.submitHint !== '';
        },

        /** Back to the picker, keeping the location and clearing the entry. */
        clearItem() {
            this.itemCode = '';
            this.itemQuery = '';
            this.unit = '';
            this.quantity = 1;
        },

        toggleDetails() {
            this.showDetails = ! this.showDetails;
        },

        /**
         * True when the page is being read in the country's own language.
         *
         * The catalogue carries an English name and a local one, and this app
         * always showed the local one. On the English page every item in the
         * list was therefore in Arabic, so a reader who had switched to English
         * could not find the thing they had just bought. The page language is
         * already on the root element — the same value the server resolved —
         * rather than being passed in a second time and allowed to disagree.
         */
        get inLocalScript() {
            const lang = (document.documentElement.lang || 'en').toLowerCase();

            return lang !== 'en' && !lang.startsWith('en-');
        },

        /**
         * The name to lead with, and the other one, for an item or a location.
         *
         * Both are shown. A reporter standing in a shop may know the item by
         * its local name and be reading the page in English, or the reverse,
         * and the dashboard table already pairs the two names for exactly this
         * reason. The second is dropped when it would repeat the first.
         */
        naming(record, localKey, otherKey) {
            const local = record[localKey] || '';
            const other = record[otherKey] || '';
            const label = (this.inLocalScript ? local : other) || local || other;
            const sub = label === local ? other : local;

            return { label, sub: sub === label ? '' : sub };
        },

        /**
         * A unit code written out in the page's language.
         *
         * Falls back to the code itself, which is what was always shown: a
         * catalogue that gains a unit before the translations catch up is
         * mildly ugly rather than blank.
         */
        unitLabel(code) {
            const unit = this.units.find((u) => u.code === code);

            if (! unit) {
                return code ?? '';
            }

            return this.naming(unit, 'name_local', 'name').label || code;
        },

        /** Locations carrying the label the template used to derive. */
        get locationOptions() {
            return this.locations.map((location) => {
                const { label, sub } = this.naming(location, 'name_local', 'name');

                // An <option> is a single line of text, so the pairing the item
                // list stacks is joined here instead.
                return { ...location, label, sub, optionLabel: sub ? `${label} · ${sub}` : label };
            });
        },

        get filteredItems() {
            const query = this.itemQuery.trim().toLowerCase();

            const matches =
                query === ''
                    ? this.items
                    : this.items.filter(
                          (item) =>
                              (item.name_local ?? '').toLowerCase().includes(query) ||
                              (item.name_en ?? '').toLowerCase().includes(query),
                      );

            // Selection state is baked in rather than compared in the template,
            // and recomputes with `itemCode` because this is a getter.
            const decorated = matches.map((item) => {
                const { label, sub } = this.naming(item, 'name_local', 'name_en');
                const isNeeded = this.needs.includes(item.code);

                return {
                    ...item,
                    label,
                    sub,
                    isNeeded,
                    unitText: this.unitLabel(item.unit),
                    className: [
                        'picker__item',
                        item.code === this.itemCode ? 'is-selected' : '',
                        isNeeded ? 'is-needed' : '',
                    ]
                        .filter(Boolean)
                        .join(' '),
                };
            });

            // Items nobody can price anywhere come first.
            //
            // The catalogue order is by weight, which is the right order for
            // the dashboard's basket list and the wrong one here: a reporter
            // adding the first price for an item moves it from "no figure at
            // all" to "a figure", which is worth more than another observation
            // of the item sixteen towns already report. `sort` is stable, so
            // weight still decides within each group.
            return decorated.sort((a, b) => Number(b.isNeeded) - Number(a.isNeeded));
        },

        get selectedItem() {
            return this.items.find((i) => i.code === this.itemCode) ?? null;
        },

        get canSubmit() {
            return (
                this.locationSlug !== '' &&
                (this.itemCode !== '' || this.itemQuery.trim() !== '') &&
                Number(this.price) > 0 &&
                !this.busy
            );
        },

        /**
         * @param {Event} event the click; CSP handlers are method references
         *                      rather than calls, so the item arrives as a data
         *                      attribute on the button rather than an argument.
         */
        selectItem(event) {
            const code = event.currentTarget?.dataset?.code;
            const item = this.items.find((candidate) => candidate.code === code);

            if (! item) {
                return;
            }

            this.itemCode = item.code;
            this.itemQuery = '';
            this.unit = item.unit;
            this.quantity = item.quantity ?? 1;

            // Move straight to the number pad: the price is the only thing left
            // and every extra tap costs seconds.
            this.$nextTick(() => this.$refs.price?.focus());
        },

        /**
         * Queue a submission.
         *
         * Writes to IndexedDB first and reports success immediately. The
         * reporter's job is done the moment it is stored locally; whether it has
         * reached the server yet is the app's problem, not theirs.
         */
        async submit() {
            if (!this.canSubmit) {
                return;
            }

            this.busy = true;

            try {
                await enqueue({
                    country: config.countryCode,
                    location_slug: this.locationSlug,
                    canonical_item_code: this.itemCode || null,
                    item_text: this.itemCode ? null : this.itemQuery.trim(),
                    price: Number(this.price),
                    currency: this.country?.currency?.code ?? null,
                    unit: this.unit || null,
                    quantity: Number(this.quantity) || 1,
                    observed_at: new Date().toISOString(),
                    device: {
                        platform: navigator.platform ?? 'web',
                        app_version: config.appVersion,
                        queued_offline: !navigator.onLine,
                    },
                });

                localStorage.setItem('qeema.location', this.locationSlug);

                // Read before the reset clears them: the message names what was
                // saved. "Saved." on its own left a reporter entering four
                // prices in a row unable to tell which one had just gone in, or
                // whether the tap had registered at all.
                const savedItem = this.chosenLabel || this.itemQuery.trim();
                const savedPrice = `${this.price} ${this.currencyLabel}`.trim();

                this.sent += 1;
                localStorage.setItem('qeema.sent', String(this.sent));

                // Light the bar. Only for a code that is actually in the
                // basket — a free-text report has no bar to light, and
                // inventing one would be the app congratulating itself.
                if (this.itemCode !== '' && ! this.filled.includes(this.itemCode)) {
                    this.filled = [...this.filled, this.itemCode];
                    localStorage.setItem('qeema.filled', JSON.stringify(this.filled));
                }

                this.resetEntry();
                this.flash(line('queued_detail', { item: savedItem, price: savedPrice }), 'success');
                await this.refreshCounts();

                if (navigator.onLine) {
                    this.sync();
                } else {
                    requestBackgroundSync();
                }
            } catch (error) {
                this.flash(String(error?.message ?? error), 'error');
            } finally {
                this.busy = false;
            }
        },

        resetEntry() {
            this.itemCode = '';
            this.itemQuery = '';
            this.price = '';
            this.unit = '';
            this.quantity = 1;
            this.showDetails = false;
            // The location is deliberately kept: a reporter submits several
            // prices from the same shop in a row.
        },

        async sync() {
            const summary = await flush(config.submitUrl, config.csrfToken);
            await this.refreshCounts();

            if (summary.sent > 0) {
                this.flash(config.messages.synced.replace(':count', String(summary.sent)), 'success');
            }

            if (summary.failed > 0) {
                this.flash(config.messages.failed.replace(':count', String(summary.failed)), 'error');
            }

            return summary;
        },

        async refreshCounts() {
            this.queue = await counts();
        },

        flash(message, kind = 'success') {
            this.flashMessage = message;
            this.flashKind = kind;

            setTimeout(() => {
                this.flashMessage = null;
            }, 4000);
        },

        get reporterId() {
            return reporterRef().slice(0, 8);
        },
    };
}
