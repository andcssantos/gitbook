# TASK-001 Database Foundation Report

## Scope

Implemented the minimum Evolvaxe database foundation for Identity, Players, Items and Containers. The legacy `game-docs/tasks/evolvaxe_v2_schema.sql` was used as a reference only; Worlds, POIs, Expeditions, Resources, Combat, Crafting, Economy, Marketplace and Upgrades were intentionally not implemented.

## Deliverables

- Migration: `database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php`
- Seeder: `database/seeds/001_evolvaxe_foundation_seed.php`
- Rule stub: `src/Game/Containers/ContainerPlacementValidator.php`
- Tests: `tests/Game/DatabaseFoundationTest.php`

## Tables Created

- `accounts`
- `players`
- `item_categories`
- `material_families`
- `material_origins`
- `item_definitions`
- `item_property_definitions`
- `item_instances`
- `item_instance_properties`
- `item_material_composition`
- `container_definitions`
- `container_instances`
- `container_items`
- `equipment_slots`
- `player_equipment`

## Seeders Created

`001_evolvaxe_foundation_seed.php` is idempotent and seeds:

- Item categories: `material`, `weapon`, `armor`, `tool`, `consumable`, `container`, `currency`
- Material families: `wood`, `metal`, `leather`, `stone`, `herb`, `essence`, `currency_metal`
- Material origins: `starter_forest`, `rocky_field`, `abandoned_mine`
- Equipment slots: `weapon`, `helmet`, `chest`, `gloves`, `pants`, `boots`, `ring`, `backpack`
- Container definitions: `main_inventory_level_1`, `small_backpack`, `medium_backpack`, `wooden_chest`, `expedition_carry`, `market_escrow`, `market_delivery`
- Minimal item definitions: `wood`, `stone`, `iron_ingot`, `gold_coin`, `iron_sword`, `stone_pickaxe`, `small_leather_backpack`

## Foreign Keys Created

- `players.account_id -> accounts.id`
- `item_definitions.category_id -> item_categories.id`
- `item_definitions.material_family_id -> material_families.id`
- `item_instances.item_definition_id -> item_definitions.id`
- `item_instances.owner_player_id -> players.id`
- `item_instances.material_origin_id -> material_origins.id`
- `item_instances.crafted_by_player_id -> players.id`
- `item_instance_properties.item_instance_id -> item_instances.id`
- `item_instance_properties.property_definition_id -> item_property_definitions.id`
- `item_material_composition.item_instance_id -> item_instances.id`
- `item_material_composition.material_family_id -> material_families.id`
- `item_material_composition.material_origin_id -> material_origins.id`
- `container_instances.container_definition_id -> container_definitions.id`
- `container_instances.owner_player_id -> players.id`
- `container_instances.source_item_instance_id -> item_instances.id`
- `container_items.container_instance_id -> container_instances.id`
- `container_items.item_instance_id -> item_instances.id`
- `player_equipment.player_id -> players.id`
- `player_equipment.equipment_slot_id -> equipment_slots.id`
- `player_equipment.item_instance_id -> item_instances.id`

## Indexes Created

Important unique indexes:

- `accounts.public_id`
- `accounts.email`
- `players.public_id`
- `players.name`
- `item_categories.code`
- `material_families.code`
- `material_origins.code`
- `item_definitions.code`
- `item_property_definitions.code`
- `item_instances.public_id`
- `item_instance_properties(item_instance_id, property_definition_id, source)`
- `container_definitions.code`
- `container_instances.public_id`
- `container_items.item_instance_id`
- `equipment_slots.code`
- `player_equipment.item_instance_id`

Important lookup/concurrency indexes:

- `players.account_id`
- `players.status`
- `item_instances(owner_player_id, item_definition_id)`
- `item_instances.state`
- `item_instance_properties(property_definition_id, numeric_value)`
- `container_instances(owner_player_id, status)`
- `container_items(container_instance_id, grid_y, grid_x)`
- `container_items.placement_version`

## Differences From Legacy V2 Schema

- Kept `accounts`, `players`, item definition/instance concepts and equipment concepts.
- Replaced legacy `inventory_containers` with `container_definitions` plus `container_instances` so reusable container types and player-owned containers are separate.
- Replaced legacy `inventory_grid_items` with `container_items` using the new naming from `01-database-model.md`.
- Added `container_definitions.allow_container_items` to support the MVP bag-in-bag block.
- Kept GridStack fields logical: `grid_x`, `grid_y`, `grid_w`, `grid_h`. No pixel columns were added.
- Did not add `player_stats`, Worlds, POIs, Expeditions, Resources, Crafting, Economy or Marketplace tables from the legacy schema.
- Kept `crafting_event_id` on `item_instances` as a nullable future reference, but did not create the FK yet because Crafting is out of scope.

## Decisions Postponed

- Identity module endpoints, login UI and account registration.
- Player starter inventory creation service.
- Full item generation/provenance tables.
- Container placement overlap validation service.
- Stack merge/split service.
- Equipment validation service.
- Economy ledger and wallet tables.
- Marketplace escrow/listing tables.
- Partitioning for high-volume future event tables. Current TASK-001 tables are foundation/state tables, not append-heavy logs.

## Risks And Assumptions

- MySQL/MariaDB is the production target; SQLite compatibility exists only for automated schema tests.
- Public IDs are stored as `CHAR(36)` in MySQL to match the legacy UUID-style schema. A later ULID decision can keep the same column length.
- `base_config` remains JSON only for low-query static configuration. Searchable properties are normalized into property tables.
- `container_items` prevents the same item instance from appearing in two containers at once through a unique `item_instance_id`.
- Inventory overlap, bounds, ownership and stale `placement_version` rules still require service-level validation in later Inventory tasks.

## Validation

Automated tests prove:

- `accounts` and `players` exist and players reference accounts.
- Item definitions are separate from item instances.
- Container definitions and instances represent main inventory and backpack.
- Container item placement stores logical GridStack coordinates.
- One item instance cannot be placed in two containers at once.
- `small_leather_backpack` is marked as a container item.
- Bag nesting can be rejected by `ContainerPlacementValidator`.
- Inventory tables do not store pixel coordinate columns.
