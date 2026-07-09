# Evolvaxe --- World Map Module

## Purpose

A World is a static illustrated progression screen containing
player-specific temporary POIs. It is not a real-time shared open world.

## World Definition

Store `code`, `name`, `description`, `visual_asset_key`,
`minimum_progression`, `base_difficulty`, `poi_slot_count`,
`poi_refresh_policy`, environmental tags, and content version.

## Player World

Store `player_id`, `world_definition_id`, persistent `world_seed`,
`unlocked_at`, `status`, and `next_poi_refresh_at`.

## Responsive POI Placement

Do not store marker pixels. Store `normalized_x` and `normalized_y` from
0 to 1. Frontend maps them to rendered image dimensions. This keeps POIs
aligned across desktop, tablet, and mobile.

## Unlock Requirements

Requirements may include progression, previous World completion, crafted
keys, combat threshold, or economic cost. Keep requirements data-driven.

## Services

`WorldAccessService`, `WorldMapQueryService`, `WorldProgressionService`.

## Tests

Access rules, persistent World Seed, normalized marker validity, and
server-side unlock validation.
