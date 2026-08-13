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
        ready: false,
        loadError: null,

        // --- what the reporter is entering ---
        locationSlug: localStorage.getItem('qeema.location') ?? '',
        itemCode: '',
        itemQuery: '',
        price: '',
        unit: '',
        quantity: 1,

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

        get currencyLabel() {
            return this.country?.currency?.symbol || this.country?.currency?.code || '';
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

        get showItemList() {
            return this.itemQuery.length > 0 || this.itemCode === '';
        },

        /** Locations carrying the label the template used to derive. */
        get locationOptions() {
            return this.locations.map((location) => ({
                ...location,
                label: location.name_local || location.name,
            }));
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
            return matches.map((item) => ({
                ...item,
                label: item.name_local || item.name_en,
                className:
                    item.code === this.itemCode
                        ? 'item-list__button is-selected'
                        : 'item-list__button',
            }));
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

                this.resetEntry();
                this.flash(config.messages.queued, 'success');
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
