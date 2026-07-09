# TASK-003 Starter Inventory Report

## Scope

Implemented the first vertical persistence flow connecting `players`, `item_instances`, `container_instances` and `container_items`. This task does not implement UI, GridStack frontend, inventory move endpoints, stacking, equipment, loot, crafting or gameplay.

## Deliverables

- `src/Support/PublicId.php`
- `src/Game/Items/Repositories/ItemDefinitionRepository.php`
- `src/Game/Items/Repositories/ItemInstanceRepository.php`
- `src/Game/Containers/Repositories/ContainerRepository.php`
- `src/Game/Containers/Repositories/ContainerDefinitionRepository.php`
- `src/Game/Containers/Repositories/ContainerInstanceRepository.php`
- `src/Game/Containers/Repositories/ContainerItemRepository.php`
- `src/Game/Inventory/Services/StarterInventoryService.php`
- `tests/Game/Inventory/StarterInventoryServiceTest.php`

## Behavior

`StarterInventoryService::ensureForPlayer()` creates, inside one transaction:

- One `main_inventory_level_1` container instance.
- One `market_delivery` container instance for future Marketplace delivery.
- One `expedition_carry` container instance for future Expedition temporary carry state.
- One `stone_pickaxe` item instance placed at `x=0, y=0, w=2, h=3`.
- One `wood` stack with quantity 10 placed at `x=2, y=0, w=1, h=2`.
- One `stone` stack with quantity 10 placed at `x=3, y=0, w=1, h=1`.
- One `small_leather_backpack` item instance placed at `x=4, y=0, w=2, h=2`.
- One linked `small_backpack` container instance using `source_item_instance_id`.

The service is idempotent. It ensures required containers exist and does not duplicate starter items when called more than once. `market_delivery` and `expedition_carry` are created as empty containers and are not used by gameplay yet.

## Local Seed Integration

`database/seeds/002_identity_local_seed.php` now calls `StarterInventoryService` after creating or updating the local player.

Local dev account:

- Email: `local@evolvaxe.test`
- Password: `evolvaxe-local`
- Player: `LocalHero`

After running the seed locally, the `evolvex` database contains:

- `container_instances = 4`
- `item_instances = 4`
- `container_items = 4`

## Security And Authority Notes

- Public IDs are generated server-side with UUID v4.
- The client does not create or place starter items.
- All placements use logical GridStack coordinates, never pixels.
- The backpack container is linked to the physical backpack item through `source_item_instance_id`.
- The unique `container_items.item_instance_id` constraint prevents one item instance from being in two containers.
- The service is ready to be reused by account/player creation flow later.

## Tests Added

- Starter inventory creates main container, starter items and linked backpack container.
- Starter inventory creation is idempotent.
- Starter placements use expected logical GridStack coordinates.
- One item instance cannot be placed in two containers.
- `market_delivery` and `expedition_carry` exist but remain empty.

## Postponed

- Inventory state API.
- Inventory move endpoint.
- Bounds and overlap service.
- Stack merge/split.
- Equipping backpack into the backpack slot.
- Starter inventory as part of public registration endpoint.
