// SPDX-License-Identifier: Apache-2.0
import Alpine from 'alpinejs';
import reporter from './reporter/app.js';

// Registered before Alpine starts so x-data="reporter(...)" resolves on the
// first paint rather than flashing an unbound template.
Alpine.data('reporter', reporter);

window.Alpine = Alpine;
Alpine.start();
