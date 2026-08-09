# ADR 0003 — Map rendering without a commercial provider

**Status:** accepted · **Date:** 2026-08-09

## Context

The dashboard needs a map of the index by location. The obvious options —
Mapbox, Google Maps, MapTiler, Stadia — all require an account and an API key,
and most bill for tile requests. Constraint C1 forbids any of that: a third
party must be able to redeploy the whole stack with no commercial accounts.

This is a place where "open source" projects routinely cheat. A repository can
be Apache-2.0 and still be unusable without someone's paid tile key. That would
fail the exact thing the funder is checking for.

## Decision

**No tile provider at all.**

- **Renderer:** MapLibre GL JS (BSD-3-Clause) — the community fork of Mapbox GL
  JS from before its licence change.
- **Geometry:** OpenStreetMap-derived boundary polygons for the country's
  locations, simplified and **bundled with the country configuration** as
  GeoJSON in `countries/<code>.geojson`.
- **Basemap:** none. Locations are drawn as choropleth polygons (or graduated
  circles where boundaries are unavailable) on a plain background.

The map is therefore a **data visualisation**, not a slippy map. There is no
tile request, no network call at runtime, and nothing to configure.

## Consequences

**It works offline and air-gapped.** The demo renders with no internet
connection, which matters for a reviewer on a poor link and for deployments in
the low-connectivity settings this platform targets.

**It is fast.** No tile fetching is a large part of how the dashboard is
expected to hold Lighthouse performance above 90 on a low-end phone.

**Attribution is required.** ODbL 1.0 obliges attribution and share-alike on
derived databases. The dashboard displays "© OpenStreetMap contributors, ODbL"
wherever geometry is shown, and `NOTICE` records it. Simplified boundaries stay
ODbL and are redistributed as such.

**Cost:** no street-level context. For an affordability index by municipality
that is not a real loss — the map answers "which areas are worse", and street
detail would be noise.

**Geometry must be sourced per country.** Adding a country means adding a
GeoJSON file alongside its YAML. Documented in the self-hosting guide. Where
boundaries are unavailable or politically contested, the config can omit them
and the dashboard falls back to graduated circles from lat/lon, which needs no
boundary data at all.

## Rejected alternatives

- **Any hosted tile provider.** Requires an account. Fails C1 outright.
- **Self-hosted tile server** (OpenMapTiles + a planet extract). OSI-licensed and
  keyless, but adds a container and tens of gigabytes of data — it would wreck
  the one-command demo.
- **Raster tiles straight from tile.openstreetmap.org.** Free, but the OSMF tile
  usage policy prohibits this kind of systematic use, and a self-hosted
  deployment would be leeching a volunteer-funded service. Not acceptable.
- **Static SVG images.** No interaction, and a new country would need new
  artwork rather than a data file.
