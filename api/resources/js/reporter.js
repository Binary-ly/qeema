// SPDX-License-Identifier: Apache-2.0
// Alpine's CSP build: it evaluates no expressions, so the reporter routes need
// no 'unsafe-eval' in their script-src. The cost is that templates may only
// reference properties and methods by name, which is why every derived value
// lives in the component rather than in the markup.
import Alpine from '@alpinejs/csp';
import reporter from './reporter/app.js';

// Registered before Alpine starts so x-data="reporter" resolves on the first
// paint rather than flashing an unbound template.
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
