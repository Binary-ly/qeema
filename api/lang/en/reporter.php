<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'Report a price',
    'subtitle' => 'Help track what a child needs costs where you live.',

    'location' => 'Where are you?',
    'location_placeholder' => 'Choose the nearest town',
    'item' => 'What did you price?',
    'item_search' => 'Search or type the item',
    'item_free_text' => 'Not in the list? Type it exactly as written.',
    'price' => 'Price',
    'unit' => 'Unit',
    'priced_for' => 'for :quantity :unit',
    'quantity' => 'Quantity',

    // No `|` in any of these on purpose. Arabic needs six plural forms and
    // these read fine without a count-dependent noun, which is cheaper than
    // maintaining six of each in three languages.
    'need_headline' => ':count of :total items have no price in any town. Yours would be the first.',
    'need_badge' => 'First price',
    'need_none' => 'Every item has a price somewhere — add today’s.',
    'meter_label' => 'The basket, item by item. A hollow bar has no price anywhere.',
    'meter_filled' => 'You have filled :count of these bars.',
    'pick_item' => 'Pick what you priced',
    'change_item' => 'Change',
    'details' => 'Quantity and unit',
    'sent_total' => 'Prices sent from this device: :count',

    // Why the save button is grey. A disabled control with no explanation is a
    // dead end: the reporter taps it, nothing happens, and nothing on the
    // screen says which of the three fields is the one still missing.
    'hint_location' => 'Choose the nearest town first.',
    'hint_item' => 'Pick what you priced, or type it.',
    'hint_price' => 'Enter the price you paid.',

    'submit' => 'Save price',
    'saving' => 'Saving…',

    'queued' => 'Saved. It will sync when you have signal.',
    // Names what was actually saved. "Saved." alone left a reporter entering
    // several prices in a row with no way to tell which one had just gone in.
    'queued_detail' => 'Saved :item at :price. It sends itself when you have signal.',
    'synced' => ':count price(s) sent.',
    'failed' => ':count submission(s) need attention.',

    'status_online' => 'Online',
    'status_offline' => 'Offline — your entries are saved on this device',
    'queue_pending' => ':count waiting to send',
    'queue_failed' => ':count could not be sent',
    'nothing_queued' => 'Everything is sent',

    'reporter_id' => 'Reporter :id',
    'load_error' => 'Could not load the item list, and no saved copy is available. Connect once to set up.',

    'offline_title' => 'You are offline',
    'offline_body' => 'Open the reporter to keep recording prices. They will send themselves when you have signal.',
    'offline_action' => 'Go to the reporter',
];
