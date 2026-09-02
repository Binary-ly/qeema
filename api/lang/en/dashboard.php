<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'Cost of a child\'s basket',
    'tagline' => 'What it costs to meet one child\'s basic needs for a month, tracked where people live.',

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
    'api_link' => 'API documentation',
    'json_link' => 'This page as JSON',
    'csv_link' => 'Download CSV',
    'source_link' => 'Source code',

    'hero_items' => 'Items priced',
    'hero_locations' => 'Locations reporting',
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

    'language' => 'Language',
    'skip_to_content' => 'Skip to content',
];
