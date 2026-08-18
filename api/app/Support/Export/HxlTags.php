<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Export;

/**
 * The HXL hashtag row for the bulk export.
 *
 * **What HXL is.** The Humanitarian Exchange Language is the tagging convention
 * the humanitarian data ecosystem runs on — OCHA's Humanitarian Data Exchange
 * ingests it, and the standard tooling around it (libhxl, the HXL Proxy,
 * quickcharts) keys off nothing else. A HXL file is an ordinary CSV with one
 * extra row directly under the human-readable header, in which every column
 * carries a machine-readable hashtag: `#date`, `#loc+name`, `#value+cost+usd`.
 *
 * **Why Qeema emits it.** Without the tag row, an analyst who wants Qeema's
 * numbers next to a food-security dataset has to hand-map column names, and the
 * mapping is guesswork the moment a column is renamed. With it, the file drops
 * into the tools that already exist. The whole point of publishing the data is
 * for somebody else to use it, and this is the format that ecosystem reads.
 *
 * **Why it is opt-in** (`?hxl=1`), rather than always present. The tag row is a
 * data row as far as any parser that has not been told about HXL is concerned:
 * `pandas.read_csv` on a tagged file yields a first record whose every field is
 * a hashtag string, and every numeric column silently becomes `object` dtype.
 * This endpoint is public, unauthenticated and already has consumers, so
 * emitting the row unconditionally would quietly corrupt their parses. HXL-aware
 * callers ask for it; nobody else is affected.
 *
 * **Hashtags are controlled, attributes are not.** The `#tag` part must come
 * from the HXL core hashtag list — `#date`, `#loc`, `#value`, `#indicator`,
 * `#currency`, `#meta` are the ones used here. The `+attribute` parts are
 * free-form, lowercase, and exist to disambiguate columns that share a hashtag:
 * three different `#value` columns are told apart by `+cost+local`, `+cost+usd`
 * and `+fx+rate`.
 */
final class HxlTags
{
    /**
     * Export column header => its HXL hashtag.
     *
     * Held as one ordered map rather than two parallel lists, so the header row
     * and the tag row cannot drift apart when a column is added in the middle.
     * They are the same declaration read two ways.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'date' => '#date',
        'location_slug' => '#loc+code',
        'location_name' => '#loc+name',
        'cost_local' => '#value+cost+local',
        'currency' => '#currency+code',
        'cost_usd' => '#value+cost+usd',
        'confidence_low' => '#value+cost+local+ci_low',
        'confidence_high' => '#value+cost+local+ci_high',
        // The comparable series. `cost_local` steps whenever the basket is
        // revised, so anyone computing inflation from this file needs the level
        // and the version that produced it.
        'index_level' => '#indicator+level+num',
        'basket_version' => '#meta+basket_version',
        'coverage' => '#indicator+coverage+pct',
        'imputed_share' => '#indicator+imputed+pct',
        // `comparable` and `quality` are Qeema's own provenance flags rather
        // than measurements, which is what `#meta` is for. An analyst who
        // ignores them gets a series that steps at every basket revision, so
        // they travel with the numbers rather than living only in the docs.
        'comparable' => '#meta+comparable',
        'quality' => '#meta+quality',
        'fx_rate' => '#value+fx+rate',
        'fx_type' => '#meta+fx_type',
        'fx_is_stale' => '#meta+fx_stale',
    ];

    /**
     * The human-readable header row.
     *
     * @return list<string>
     */
    public static function header(): array
    {
        return array_keys(self::COLUMNS);
    }

    /**
     * The hashtag row that sits directly beneath it.
     *
     * @return list<string>
     */
    public static function row(): array
    {
        return array_values(self::COLUMNS);
    }
}
