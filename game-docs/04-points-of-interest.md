# Evolvaxe --- Points of Interest Module

## Purpose

A POI is a temporary, player-specific opportunity on a World Map. It
creates an Expedition.

## POI Template

Fields: `code`, `name_pattern`, `category`, `biome_id`,
`base_duration_seconds`, difficulty range, enemy pool, resource pool,
chest rule, layout generator, expiration range, weight, content version.

Categories: mine, grove, ruins, den, cavern, camp, marsh, anomaly.

## Player POI Instance

Store public ID, player World, template, POI seed, difficulty,
normalized map coordinates, generation time, availability, expiration,
status, Expedition duration, and generation version.

Statuses: `AVAILABLE`, `ENTERED`, `COMPLETED`, `EXPIRED`, `CONSUMED`,
`INVALIDATED`.

## Lifecycle

Refresh cycle → fill missing POI slots → persist generated POIs → player
chooses → server validates → Expedition created → POI consumed/entered →
Expedition ends → POI disappears.

## Timing

POI availability is not Expedition duration. Example: POI exists for 20
minutes; after entry the Expedition lasts 60 seconds.

## Anti-Reroll

Page refresh, cache clearing, or reconnect must never generate
replacement POIs. Generation state is server-side.

## Services

`PoiGenerationService`, `PoiLifecycleService`, `PoiAvailabilityService`,
`PoiQueryService`.

## Tests

Deterministic generation, weighted selection, expiration, no duplicate
slot generation, no refresh reroll, expired-entry rejection.
