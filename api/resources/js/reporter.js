// SPDX-License-Identifier: Apache-2.0
import Alpine from 'alpinejs';
import reporter from './reporter/app.js';

// Registered before Alpine starts so x-data="reporter(...)" resolves on the
// first paint rather than flashing an unbound template.
Alpine.data('reporter', reporter);

window.Alpine = Alpine;
Alpine.start();

// Service worker registration. Bundled rather than inline so the reporter can
// be served under a Content-Security-Policy that forbids inline script.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
    })
}
