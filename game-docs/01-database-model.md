# Evolvaxe --- Database Model

## Goal

Replace the legacy prototype's JSON-heavy relationships with a
relational, modular schema suitable for procedural state, unique items,
GridStack inventory, crafting, and a shared economy. The legacy schema
already contains players, bags, maps, monsters, resources, and items,
but important gameplay lists and bag contents are JSON-oriented.

## Naming

Use English plural `snake_case` table names and English columns. Use
internal numeric IDs plus ULID/UUID public IDs for exposed unique
entities.

## Domain Groups

-   Identity: `accounts`, `players`, `player_settings`,
    `player_progression`
-   Worlds: `world_definitions`, `player_worlds`,
    `point_of_interest_templates`, `player_points_of_interest`
-   Expeditions: `expedition_instances`, `expedition_entities`,
    `expedition_events`
-   Resources: `resource_source_definitions`,
    `resource_source_drop_tables`, `resource_source_drop_entries`,
    `material_definitions`, `material_origins`, `material_instances`,
    `material_compositions`
-   Items: `item_definitions`, `item_instances`,
    `item_property_definitions`, `item_instance_properties`,
    `item_family_property_pools`, `item_naming_rules`
-   Inventory: `container_definitions`, `container_instances`,
    `container_items`, `equipment_slots`, `player_equipment`
-   Crafting: `crafting_station_definitions`,
    `crafting_station_instances`, `recipe_definitions`, `recipe_inputs`,
    `recipe_outputs`, `proficiency_definitions`, `player_proficiencies`,
    `crafting_events`, `crafting_event_inputs`
-   Economy: `wallets`, `wallet_ledger_entries`, `market_listings`,
    `market_transactions`, `market_price_aggregates`
-   Operations: `game_audit_logs`, `idempotency_keys`, `outbox_events`

## Definitions vs Instances

Definitions are static game content. Instances are player-owned,
session-owned, or generated state. Never put player ownership on
definition tables.

## JSON Policy

JSON is acceptable for cosmetic metadata, snapshots, low-query
configuration, debug payloads, and append-only audit context. Do not use
JSON as primary storage for inventory placement, recipe inputs, item
properties, marketplace filters, drop entries, equipment ownership, or
POI ownership.

## Foreign Keys

Use explicit FKs. Cascade only when child history has no independent
value. Never cascade-delete wallet ledger or completed Marketplace
history.

## Important Indexes

-   `(player_world_id, status, expires_at)` on POIs
-   `(player_id, status, ends_at)` on Expeditions
-   `(owner_player_id, item_definition_id)` on item instances
-   `(property_definition_id, numeric_value)` on item properties
-   `(container_instance_id)` on placements
-   `(status, item_definition_id, unit_price)` on listings
-   `(wallet_id, created_at)` on ledger
-   `(expedition_instance_id, sequence_number)` on Expedition events

## Currency

Use BIGINT integer coin units. Never FLOAT/DOUBLE for money. Every
mutation creates a ledger entry.

## Immutable Generation

Unique items preserve generation seed, recipe/content version, material
composition, crafter proficiency snapshot, station snapshot, and final
properties. Never regenerate historical items from current balance
configuration.

## Transactions

Required for crafting, inventory transfer, equipment/container
transitions, Marketplace listing/purchase, minting, upgrades, and
Expedition settlement.

## Legacy Migration

Create V2 beside the legacy `evo_*` schema. Migrate identities and
reusable definitions. Convert bag/inventory JSON into container and
placement rows. Keep legacy data read-only until validation completes.
