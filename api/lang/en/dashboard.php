<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'What a child\'s month costs, town by town',
    'tagline' => 'The price this week of the fifteen things one child needs — food, formula, medicine, school supplies, gas, water — at the exchange rate people actually pay. Free for anyone to use.',
    'door_report' => 'I have a price',
    'door_report_sub' => 'Report one price from your town. Two taps, works offline.',
    'door_data' => 'I need the number',
    'door_data_sub' => 'API, CSV, or this page as JSON. No key, no account.',
    'afford_partial' => 'In :location, the :priced items with a price come to :cost :currency — :share% of :income_label.',
    'afford_full' => 'A child\'s month in :location costs :cost :currency — :share% of :income_label.',
    'afford_basis' => 'Against :income_label, :income :currency a month.',
    'list_lead' => 'In :location, this month:',
    'list_priced' => ':priced of :total priced.',
    'list_estimated' => 'estimated',
    'list_none' => 'no price yet',
    'list_total' => 'Total for the priced items: :cost :currency.',
    'qr_title' => 'Hand this out',
    'qr_body' => 'Anyone who scans it can report a price from their town. Print it, or send the link.',
    'qr_alt' => 'QR code linking to the price reporter',

    'headline_median' => 'Median across comparable locations',
    'headline_usd' => 'In US dollars',
    'headline_spread' => 'Cheapest to dearest',
    'headline_locations' => 'Comparable locations',
    'as_of' => 'As of :date',
    'no_data' => 'No published figures yet.',
    'no_comparable' => 'No location has a fully-priced basket yet, so there is no comparable median to publish. The figures below are still accurate for each location on its own.',
    'no_data_body' => 'Once prices have been reported and the index has run, they appear here.',

    'map_title' => 'Where it costs most',
    'map_desc' => 'Each point is a reporting location, shaded by the cost of the same basket. Hollow points are not comparable — see below.',
    'map_alt' => 'Map of reporting locations shaded by basket cost.',
    'legend_cheaper' => 'Less expensive',
    'legend_dearer' => 'More expensive',
    'legend_incomparable' => 'Not comparable',

    'comparable_note' => 'Not comparable',
    'comparable_explain' => 'Part of this basket has no price, so its total is not measured on the same footing as a fully-priced location. A partly-priced basket costs less simply because part of it is missing, which would make a place with thin reporting look cheap. Those locations are shown but not ranked.',

    'quality' => 'Data quality',
    'quality_good' => 'Good',
    'quality_moderate' => 'Moderate',
    'quality_low' => 'Low',
    'coverage' => 'Coverage',
    'imputed' => 'Estimated',
    'imputed_explain' => 'Share of the basket priced by model rather than observed. Estimated values are never presented as measurements.',
    'observed' => 'Observed',

    'chart_national' => 'Basket cost over time',
    'chart_national_desc' => 'Median across locations with a fully-priced basket.',
    'chart_await' => 'No line yet — a trend needs two dates on which somewhere priced the whole basket.',
    'chart_locations' => 'By location',
    'chart_fx' => 'Exchange rate',
    'chart_fx_desc' => 'Official and parallel rates. The gap between them is often the earliest visible sign of stress.',
    'chart_premium' => 'Parallel premium',
    'chart_official' => 'Official',
    'chart_parallel' => 'Parallel',
    'chart_unavailable' => 'Not enough history to chart yet.',

    'table_title' => 'Every location',
    'table_location' => 'Location',
    'table_cost' => 'Basket cost',
    'table_coverage' => 'Coverage',
    'table_quality' => 'Quality',
    'table_updated' => 'Updated',
    'days_ago' => ':count day ago|:count days ago',
    'today' => 'Today',

    'use_the_data' => 'Use this data',
    'use_the_data_body' => 'Everything on this page is available through a public API that needs no key, and as a CSV download. The data is CC BY 4.0; the software is Apache-2.0.',
    // Beside the specimen response, pointing at the one highlighted line.
    // Deliberately not `imputed_explain`, which says the same thing under the
    // table: the same sentence twice on one page reads as a template, not a
    // point.
    'spec_note' => 'The highlighted field rides in every response. An estimate is always labelled as one, in the API as much as on this page.',
    'api_link' => 'API documentation',
    'json_link' => 'This page as JSON',
    'csv_link' => 'Download CSV',
    'source_link' => 'Source code',

    'hero_items' => 'Items priced',
    // Not "Locations reporting". The number counts locations that have a
    // published figure, which is not the same as locations that are reporting
    // now — this deployment shows sixteen of them and the newest observation
    // behind any of them is months old. A present participle over a stale
    // count is the same overstatement as dating old prices today.
    'hero_locations' => 'Locations with data',
    'hero_updated' => 'Latest data',

    'basket_title' => 'What a child needs',
    'basket_desc' => 'The basket being costed, heaviest item first. The weight is the share of a household\'s spend on a child that the item represents — so an item with no price costs the index far more at the top of this list than at the bottom.',
    'basket_item' => 'Item',
    'basket_weight' => 'Weight',
    'basket_where' => 'Priced in',
    'basket_locations' => ':count of :total locations',
    'basket_none' => 'No price anywhere',
    'basket_stack_label' => ':percent% of a child\'s basket, by weight, has no price in any location here.',
    'basket_gap' => ':count of :total items a child needs have no price in any location here. Those are the gap this platform exists to close.',
    // The same fact as the two lines above, said the way a person would say it.
    // "By weight" and "location" are the index talking; this is what the index
    // is about. No `|`: Arabic needs six plural forms and these read without a
    // count-bound noun.
    'gap_lead' => 'For :count of the :total things a child needs this month, no one has recorded a price. Not in any town.',
    'gap_hollow' => 'The hollow ones have no price anywhere. They are the gap this platform exists to close.',

    'footer_license' => 'Data :license · Software Apache-2.0 · Figures republish freely with attribution.',

    'language' => 'Language',
    'skip_to_content' => 'Skip to content',
];
