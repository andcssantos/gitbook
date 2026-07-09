# Evolvaxe --- Inventory and GridStack Module

## Core Rule

GridStack is the visual layout engine. The database stores logical
geometry: columns, rows, x, y, width, height. Never store pixels.

## Container Definition

Store code, name, type, grid columns/rows, allowed item rule, equippable
flag, container-nesting rule, visual asset, content version.

Types: `MAIN_INVENTORY`, `BACKPACK`, `CHEST`, `STASH`,
`EXPEDITION_CARRY`, `MARKET_ESCROW`, `MARKET_DELIVERY`.

## Container Instance

Store public ID, definition, owner player, optional source item
instance, status, timestamps.

## Placement

`container_items`: container, item instance, `grid_x`, `grid_y`,
`grid_width`, `grid_height`, placement version.

Item definition is the normal size authority.

## GridStack Mapping

Database x/y/w/h map directly to GridStack x/y/w/h.

## Responsive Rendering

Logical grid never changes because of viewport size. A 10x6 inventory
remains 10x6. Frontend calculates cell pixels from available width and
clamps a minimum/maximum size. Use `disableOneColumnMode: true`.

Desktop may use 48px cells, tablet 38px, mobile 28px. A 1x3 sword
remains 1x3.

## Mobile

Use a fullscreen inventory modal, responsive cell size, touch drag,
tap-to-inspect, and Move/Split accessibility actions. Use scroll only
when minimum usable cell size cannot fit.

## Server Placement Validation

Validate ownership, container access, bounds, dimensions, overlap,
family restrictions, nesting, and expected placement version. Client
collision is not authoritative.

## Concurrency

Move command includes item ID, source/destination container IDs, x/y,
and expected version. Reject stale moves and return authoritative
layout.

## Stacking

Compatible stacks require same definition, compatible origin rule, same
quality bucket, and compatible trade/binding state. Merge with max-stack
validation and weighted average exact quality/composition.

## Pickup

Try compatible stacks, then deterministic free-grid placement. If no
fit, leave loot on Expedition ground and show `Inventory Full`. Never
silently delete loot.

## Rotation

Disable in MVP. If introduced later, persist `is_rotated` and validate
swapped dimensions.

## Tests

Placement, overlap, bounds, responsive invariance, mobile layout, merge
quality, split stack, stale version, inventory full, reload.
