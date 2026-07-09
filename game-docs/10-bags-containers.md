# Evolvaxe --- Bags and Containers Module

## Core Model

A backpack is both an Item Instance and the source of a Container
Instance.

Example: Small Leather Backpack occupies 2x2 in an external inventory
and exposes a 4x4 internal grid. External size and internal capacity are
independent.

## Backpack Equipment

Create a dedicated `BACKPACK` equipment slot. When equipped, the linked
container becomes active and visible.

## MVP Unequip Rule

A backpack must be empty before unequipping. This avoids inaccessible
nested contents and ownership edge cases.

## Nesting

Initial rule: containers cannot contain item definitions where
`is_container = true`. Block bag-inside-bag and recursive containment
server-side.

## Main Inventory Upgrades

Main inventory is progression-backed, not a physical bag. Example sizes:
8x5 → 9x5 → 10x5 → 10x6. Existing item coordinates remain unchanged when
the grid expands.

## Specialized Bags

Optional rules: Ore Bag accepts MINERAL, Herb Satchel accepts HERB.
Restrictions belong to data definitions, not frontend labels.

## Pickup Priority

Compatible existing stack → specialized equipped bag → main inventory →
general backpack.

## Services

`ContainerService`, `BackpackEquipmentService`,
`ContainerPlacementService`, `ContainerRestrictionService`,
`PickupPlacementService`.

## Tests

Linked container, equipment activation, nesting rejection, restrictions,
upgrade preservation, non-empty unequip rejection, pickup priority.
