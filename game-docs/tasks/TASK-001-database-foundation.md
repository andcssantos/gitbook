# TASK-001 — Database Foundation

## Goal

Create the first database foundation for Evolvaxe using the existing `evolvaxe_v2_schema.sql` as reference, but do **not** blindly import the full schema yet.

The purpose of this task is to establish only the minimum safe database foundation required before implementing the first gameplay modules.

This task focuses on:

1. Identity and Player baseline.
2. Item definitions and item instances.
3. Item properties.
4. Inventory containers compatible with GridStack.
5. Container item placement.
6. Basic equipment slots.
7. Minimal migrations and seeders for testing.

Do not implement gameplay yet.

---

# Files to Read First

Cursor must read:

- `game-docs/00-game-vision.md`
- `game-docs/01-database-model.md`
- `game-docs/08-items.md`
- `game-docs/09-inventory-gridstack.md`
- `game-docs/10-bags-containers.md`
- `game-docs/18-cursor-development-rules.md`
- `game-docs/19-security-server-authority.md`
- `game-docs/tasks/TASK-000-security-baseline-report.md`
- `database/reference/evolvaxe_v2_schema.sql` or the current location of the uploaded V2 schema.

---

# Important Context

The current V2 schema is useful as a **reference architecture**, but it is too large to implement as the first migration all at once.

Do not create all gameplay tables in one step.

Do not create Worlds, POIs, Expeditions, Resources, Combat, Crafting, Economy, Marketplace, Upgrades or full gameplay audit tables in this task unless they are already part of the security infrastructure.

The first implementation must be small, testable and stable.

---

# Tables to Implement in This Task

Implement migrations only for the following foundation tables:

## 1. accounts

Purpose:

Stores login identity.

Required fields:

- `id`
- `public_id`
- `display_name`
- `email`
- `password_hash`
- `status`
- `created_at`
- `updated_at`
- `deleted_at`

Required indexes:

- unique `public_id`
- unique `email`
- index `status`

Security rules:

- Password hash must be compatible with Argon2i or Argon2id.
- Never store reversible passwords.
- Public API must use `public_id`, not sequential `id`.

---

## 2. players

Purpose:

Stores the playable character/profile linked to an account.

Required fields:

- `id`
- `public_id`
- `account_id`
- `name`
- `avatar_key`
- `level`
- `experience`
- `base_expedition_seconds`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `public_id`
- unique `name`
- index `account_id`
- index `status`

Foreign key:

- `account_id` references `accounts(id)` with cascade delete.

Initial rule:

One account may own one or more players, but the MVP may create only one player per account if the current game flow needs that.

---

## 3. item_categories

Purpose:

Groups item definitions.

Examples:

- material
- weapon
- armor
- tool
- consumable
- container
- currency

Required fields:

- `id`
- `code`
- `name`
- `description`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `status`

---

## 4. material_families

Purpose:

Defines material families used by resource, crafting and item-generation systems.

Examples:

- wood
- metal
- leather
- stone
- herb
- essence
- currency_metal

Required fields:

- `id`
- `code`
- `name`
- `description`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `status`

---

## 5. material_origins

Purpose:

Defines where materials came from.

Examples:

- starter_forest
- rocky_field
- ancient_grove
- abandoned_mine

Required fields:

- `id`
- `code`
- `name`
- `description`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `status`

---

## 6. item_definitions

Purpose:

Defines the base item family/template.

Important:

This is not the player-owned item. This is only the base definition.

Examples:

- Iron Sword
- Wood
- Iron Ingot
- Small Leather Backpack
- Gold Coin
- Stone Pickaxe

Required fields:

- `id`
- `code`
- `name`
- `description`
- `category_id`
- `material_family_id`
- `stackable`
- `max_stack`
- `grid_w`
- `grid_h`
- `equip_slot_code`
- `is_container`
- `tradeable`
- `base_config`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `category_id`
- index `material_family_id`
- index `status`
- index `equip_slot_code`

Foreign keys:

- `category_id` references `item_categories(id)`
- `material_family_id` references `material_families(id)` nullable

Rules:

- `grid_w` and `grid_h` are logical GridStack units.
- Never store pixel dimensions.
- `stackable = 0` means each instance quantity must normally be 1.
- `is_container = 1` means the item may create or link to a container instance.
- `base_config` may be JSON for low-query static configuration, but core searchable properties must not be stored only inside JSON.

---

## 7. item_property_definitions

Purpose:

Defines possible generated item properties.

Examples:

- min_damage
- max_damage
- attack_speed
- critical_chance
- defense
- extraction_power
- extraction_efficiency
- rare_discovery
- max_health

Required fields:

- `id`
- `code`
- `name`
- `value_type`
- `unit`
- `min_value`
- `max_value`
- `market_filterable`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `market_filterable`
- index `status`

Rules:

- These definitions do not store actual item values.
- Actual values belong to `item_instance_properties`.

---

## 8. item_instances

Purpose:

Stores actual player-owned item instances or stack instances.

Examples:

- specific Iron Sword #ABC with generated stats.
- stack of Fine Wood x48.
- specific Small Backpack instance.

Required fields:

- `id`
- `public_id`
- `item_definition_id`
- `owner_player_id`
- `quantity`
- `quality_value`
- `quality_bucket`
- `material_origin_id`
- `item_name`
- `crafted_by_player_id`
- `crafting_event_id`
- `current_durability`
- `max_durability`
- `bind_type`
- `state`
- `created_at`
- `updated_at`

Required indexes:

- unique `public_id`
- index `owner_player_id`
- index `item_definition_id`
- index `(owner_player_id, item_definition_id)`
- index `quality_bucket`
- index `material_origin_id`
- index `state`

Foreign keys:

- `item_definition_id` references `item_definitions(id)`
- `owner_player_id` references `players(id)` nullable
- `material_origin_id` references `material_origins(id)` nullable
- `crafted_by_player_id` references `players(id)` nullable

Rules:

- Unique equipment must always be an `item_instance`.
- Stackable materials may use quantity.
- Item stats must not be stored on `item_definitions`.
- Exact item generation result must persist forever.
- Do not reroll item stats after creation.

---

## 9. item_instance_properties

Purpose:

Stores generated or assigned properties for item instances.

Required fields:

- `id`
- `item_instance_id`
- `property_definition_id`
- `numeric_value`
- `integer_value`
- `text_value`
- `source`
- `created_at`

Required indexes:

- index `item_instance_id`
- index `property_definition_id`
- index `(property_definition_id, numeric_value)`
- unique `(item_instance_id, property_definition_id, source)` if compatible with the intended property system

Foreign keys:

- `item_instance_id` references `item_instances(id)` with cascade delete
- `property_definition_id` references `item_property_definitions(id)`

Rules:

- Use numeric columns for searchable properties.
- Do not hide generated stats only in JSON.

---

## 10. item_material_composition

Purpose:

Stores composition history for materials and crafted items.

Example:

An Iron Ingot may be:

- 70% rocky_field iron
- 30% starter_mine iron

Required fields:

- `id`
- `item_instance_id`
- `material_family_id`
- `material_origin_id`
- `percentage`
- `average_quality`
- `created_at`

Required indexes:

- index `item_instance_id`
- index `material_family_id`
- index `material_origin_id`

Foreign keys:

- `item_instance_id` references `item_instances(id)` with cascade delete
- `material_family_id` references `material_families(id)`
- `material_origin_id` references `material_origins(id)`

Rules:

- Total composition per item should be validated by service logic.
- Do not rely only on JSON for composition.

---

## 11. container_definitions

Purpose:

Defines reusable container types.

This table may not exist in the uploaded V2 schema, but it should be added because it improves modularity.

Examples:

- main_inventory_level_1
- small_backpack
- medium_backpack
- wooden_chest
- expedition_carry
- market_escrow
- market_delivery

Required fields:

- `id`
- `code`
- `name`
- `container_type`
- `grid_columns`
- `grid_rows`
- `allow_container_items`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `container_type`
- index `status`

Rules:

- `grid_columns` and `grid_rows` are logical units.
- Do not store pixels.
- `allow_container_items = 0` for backpacks in the MVP to prevent bag-in-bag nesting.

---

## 12. container_instances

Purpose:

Stores actual containers owned by players.

This should replace or adapt the uploaded schema table `inventory_containers`.

Required fields:

- `id`
- `public_id`
- `container_definition_id`
- `owner_player_id`
- `source_item_instance_id`
- `name`
- `grid_columns`
- `grid_rows`
- `status`
- `sort_order`
- `created_at`
- `updated_at`

Required indexes:

- unique `public_id`
- index `(owner_player_id, status)`
- index `container_definition_id`
- index `source_item_instance_id`

Foreign keys:

- `container_definition_id` references `container_definitions(id)`
- `owner_player_id` references `players(id)` with cascade delete
- `source_item_instance_id` references `item_instances(id)` nullable

Rules:

- Main inventory has no `source_item_instance_id`.
- A backpack container uses `source_item_instance_id` to link to the physical backpack item.
- Market escrow and market delivery containers will be created later using the same abstraction.
- Store `grid_columns` and `grid_rows` as a snapshot so upgraded containers can keep their historical size.

---

## 13. container_items

Purpose:

Stores GridStack item placement inside containers.

This should replace or adapt the uploaded schema table `inventory_grid_items`.

Required fields:

- `id`
- `container_instance_id`
- `item_instance_id`
- `grid_x`
- `grid_y`
- `grid_w`
- `grid_h`
- `rotated`
- `locked`
- `placement_version`
- `created_at`
- `updated_at`

Required indexes:

- unique `item_instance_id`
- index `(container_instance_id, grid_y, grid_x)`
- index `placement_version`

Foreign keys:

- `container_instance_id` references `container_instances(id)` with cascade delete
- `item_instance_id` references `item_instances(id)` with cascade delete

Rules:

- `grid_x`, `grid_y`, `grid_w`, `grid_h` are logical GridStack values.
- Never store pixel values.
- Server must validate bounds and overlap.
- GridStack frontend is not authoritative.
- `placement_version` supports optimistic concurrency.

---

## 14. equipment_slots

Purpose:

Defines valid equipment slots.

Examples:

- weapon
- helmet
- chest
- gloves
- pants
- boots
- ring
- backpack

Required fields:

- `id`
- `code`
- `name`
- `sort_order`
- `status`
- `created_at`
- `updated_at`

Required indexes:

- unique `code`
- index `status`

---

## 15. player_equipment

Purpose:

Stores currently equipped items.

Required fields:

- `player_id`
- `equipment_slot_id`
- `item_instance_id`
- `equipped_at`

Primary key:

- `(player_id, equipment_slot_id)`

Additional constraints:

- unique `item_instance_id` if one item cannot be equipped twice.

Foreign keys:

- `player_id` references `players(id)` with cascade delete
- `equipment_slot_id` references `equipment_slots(id)`
- `item_instance_id` references `item_instances(id)`

Rules:

- Equipping an item must be validated by service logic.
- Equipping a backpack may activate its linked container.
- Unequipping a backpack in MVP requires it to be empty.

---

# Tables Not to Implement Yet

Do not implement these in TASK-001:

- world_definitions
- player_worlds
- point_of_interest_definitions
- player_points_of_interest
- expedition_runs
- expedition_entities
- resource_source_definitions
- monster_definitions
- chest_definitions
- loot_tables
- loot_table_entries
- crafting_station_definitions
- player_crafting_stations
- proficiency_definitions
- player_proficiencies
- crafting_recipes
- crafting_recipe_inputs
- crafting_events
- wallets
- wallet_transactions
- market_listings
- market_transactions
- market_price_snapshots
- upgrade_definitions
- player_upgrades

These belong to later tasks.

---

# Seeders Required in This Task

Create minimal seeders for:

## Item categories

- material
- weapon
- armor
- tool
- consumable
- container
- currency

## Material families

- wood
- metal
- leather
- stone
- herb
- essence
- currency_metal

## Material origins

- starter_forest
- rocky_field
- abandoned_mine

## Equipment slots

- weapon
- helmet
- chest
- gloves
- pants
- boots
- ring
- backpack

## Container definitions

- main_inventory_level_1: 8 columns x 5 rows
- small_backpack: 4 columns x 4 rows
- medium_backpack: 6 columns x 5 rows
- wooden_chest: 10 columns x 8 rows
- expedition_carry: 8 columns x 5 rows
- market_escrow: 10 columns x 10 rows
- market_delivery: 10 columns x 10 rows

## Minimal item definitions

- wood
- stone
- iron_ingot
- gold_coin
- iron_sword
- stone_pickaxe
- small_leather_backpack

Make sure each item has logical `grid_w` and `grid_h`.

Example:

- gold_coin: 1x1, stackable
- wood: 1x2, stackable
- iron_ingot: 1x1, stackable
- iron_sword: 1x3, not stackable
- stone_pickaxe: 2x3, not stackable
- small_leather_backpack: 2x2, not stackable, is_container

---

# Required Repository/Service Pattern

Do not place database logic directly in controllers.

Recommended folders:

- `src/Game/Identity`
- `src/Game/Player`
- `src/Game/Items`
- `src/Game/Inventory`
- `src/Game/Containers`

For this task, if only migrations/seeders are implemented, services may be stubbed or postponed.

If any endpoint is created, it must use:

- Controller
- Service
- Repository
- Validator or DTO

---

# Security Rules

- Use DB transactions for creating a player with starter inventory.
- Do not use `App\Core\Model::select($where)` for repositories.
- Use bound parameters.
- Do not trust frontend for item ownership or inventory placement.
- Do not store GridStack pixels.
- Do not allow container nesting in MVP.
- Public APIs must expose `public_id`, not internal numeric IDs.

---

# Initial Test Requirements

Add tests or test scripts proving:

1. Accounts table exists.
2. Players table exists and references accounts.
3. Item definitions are separated from item instances.
4. Container definitions exist.
5. Container instances can represent main inventory and backpack.
6. Container item placement stores logical GridStack coordinates.
7. Unique item instances cannot appear in two containers at the same time.
8. Backpack item definition can be marked as container.
9. Container nesting can be rejected by service or validation rule.
10. No table stores inventory pixel coordinates.

---

# Expected Deliverables

Cursor must create:

1. Migration files for the TASK-001 tables only.
2. Seeder files for minimal definitions.
3. Optional small repository/service stubs if needed by the framework.
4. A report file:

`game-docs/tasks/TASK-001-database-foundation-report.md`

The report must include:

- tables created;
- seeders created;
- foreign keys created;
- indexes created;
- differences from `evolvaxe_v2_schema.sql`;
- decisions intentionally postponed;
- risks or assumptions.

---

# Explicit Scope Boundaries

Do not implement:

- UI.
- GridStack frontend.
- Auth screens.
- Worlds.
- POIs.
- Expeditions.
- Resource extraction.
- Combat.
- Crafting.
- Item generation.
- Economy.
- Wallets.
- Marketplace.
- Upgrades.
- Full audit events beyond existing infrastructure.

Only build the database foundation needed for Identity, Items and Containers.
