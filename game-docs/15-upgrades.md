# Evolvaxe --- Upgrade and Progression Module

## Categories

`EXPEDITION_TIME`, `MAIN_INVENTORY_SIZE`, `STORAGE_SIZE`,
`POI_DISCOVERY`, `CRAFTING_STATION`, `WORLD_ACCESS`.

Do not keep upgrades in one JSON skill object.

## Definitions

Upgrade definition stores code, category, max level, effect type,
content version, status.

Upgrade level rows store level, coin cost, optional item requirements,
effect value, and prerequisites.

## Player Upgrade

Store player, upgrade definition, current level, acquired/updated
timestamps.

## Expedition Time

Example: level 0 +0s, level 1 +5s, level 2 +10s, level 3 +15s. Apply at
Expedition creation.

## Main Inventory

Example logical sizes: 8x5 → 9x5 → 10x5 → 10x6. Expand safely without
changing existing placements.

## POI Discovery

Possible effects: extra active POI slot, slightly improved special-POI
weighting, faster refresh. Never guarantee the rarest POI.

## Services

`UpgradeQueryService`, `UpgradePurchaseService`, `UpgradeEffectService`.

## Tests

Costs, prerequisites, max level, idempotency, timer effect, grid
expansion, ledger integration.
