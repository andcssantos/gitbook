# Evolvaxe --- Item System

## Item Definition

Static family data: code, display name, item family, type, description,
icon, logical `grid_width`, `grid_height`, max stack,
stackable/equippable/container/tradeable/dismantlable flags, content
version, status.

## Item Instance

Persistent object or controlled stack: public ID, definition, owner,
quantity, exact quality, material origin, generation seed/version,
durability, crafter, crafting event, timestamps.

Unique equipment always has quantity 1.

## Properties

Normalize properties through `item_property_definitions` and
`item_instance_properties`.

Examples: min damage, max damage, attack speed, critical chance,
defense, extraction power, extraction efficiency, rare discovery, damage
against beasts.

## Eligibility

Item family property pools define valid properties. A sword cannot roll
rare mineral discovery. A pickaxe can.

## Generation

Inputs: recipe/version, selected materials, quality, composition,
origins, proficiency, station, seed. Outputs: base attributes, generated
properties, durability, name, provenance.

## Naming

Generate names after stats. Names describe tendencies but never create
stats.

## Rarity

Traditional rarity is not authoritative item value. Actual properties
and market demand matter.

## Services

`ItemQueryService`, `ItemGenerationService`, `ItemPropertyService`,
`ItemNamingService`, `ItemOwnershipService`, `ItemDismantlingService`.

## Tests

Unique ID, property eligibility, no reroll, grid dimensions, stack
rules, ownership transfer, provenance.
