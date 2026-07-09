# Evolvaxe --- Game Vision

## Identity

Evolvaxe is a session-based procedural crafting economy RPG for the web.
It is not an idle game, AFK game, open-world MMORPG, or auto-battler.

## Core Loop

World Map → temporary Point of Interest → timed Expedition →
gather/fight/search → manage geometric inventory → extract loot →
process materials → craft unique items → equip/store/sell → upgrade →
access harder Worlds.

## Design Pillars

-   **Active opportunity:** Expeditions are short and time-limited.
-   **Inventory pressure:** items occupy logical X/Y grid space.
-   **Resource depth:** tools change extraction access, quality
    preservation, yield, and rare discovery.
-   **Crafting is item generation:** recipes define product families,
    not exact outputs.
-   **Emergent value:** supply, demand, utility, scarcity, and generated
    properties create price.
-   **Private progression, shared economy:** POIs and Expeditions are
    player-specific; Marketplace is global.
-   **Combinatorial discovery:** thousands of outcomes come from
    systems, not thousands of manually hardcoded items.

## World Model

A World is a progression layer and illustrated map. It defines POI
pools, biomes, enemy families, resource families, difficulty ranges, and
modifiers.

## POI Model

A POI is a temporary opportunity generated for one player from a
persistent seed. It has availability and expiration. Entering it creates
an Expedition.

## Expedition Model

Default initial duration: 60 seconds. The player actively moves,
gathers, fights, and searches. Server time is authoritative.

## Item Philosophy

Separate Item Definition from Item Instance. `Iron Sword` is a
definition. A sword with ID, 14--21 damage, +4.2% attack speed,
provenance, and durability is an instance.

## Scale Target

Support 5,000--10,000+ meaningful craft outcomes by combining base
items, material families, origins, quality, recipes, station modifiers,
proficiency, generated properties, tiers, and naming rules.

## Non-Negotiable Rules

-   Never store GridStack coordinates in pixels.
-   Never make client state authoritative for rewards, currency,
    crafting, or Marketplace settlement.
-   Never reroll POIs, Expedition rewards, or crafts on browser reload.
-   Never represent unique equipment only as definition ID + quantity.
-   Never use opaque JSON for relationships that require joins, filters,
    indexes, or foreign keys.
-   Never make traditional color rarity the authoritative item value.
-   Never create infinite NPC money sources in the core economy.
-   Block recursive bag nesting in the MVP.

## Architecture

Every mechanic is a module with domain rules, persistence, services,
validation, events, API boundary, UI state, tests, and audit
requirements.
