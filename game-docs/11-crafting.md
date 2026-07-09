# Evolvaxe --- Crafting Module

## Principle

A recipe defines a product family. It does not necessarily define exact
final stats.

## Recipe Definition

Store code, name, category, station type/tier, proficiency requirement,
output definition, output mode, base craft time, content version,
status.

Output modes: `FIXED_STACK`, `PROCESSED_MATERIAL`, `UNIQUE_ITEM`.

## Recipe Inputs

Use relational rows with input slot code, accepted
definition/family/tag, quantity, selection requirement, quality weight,
and composition weight.

## Exact Material Selection

For quality-sensitive crafts, the player selects exact stack instances.
The server must not consume arbitrary matching materials.

## Craft Transaction

Validate station and recipe → validate exact inputs and ownership → lock
stacks → create Crafting Event and seed → consume inputs → calculate
output → create item → place in controlled output container → commit →
publish event.

## Crafting Event

Persist player, recipe/version, station, proficiency snapshot, craft
seed, calculation version, status, timestamps. Preserve consumed input
snapshots/composition.

## Processed Materials

Quality derives from weighted input quality, composition, station,
proficiency when relevant, and deterministic variance.

## Unique Items

Crafting owns the production event. `ItemGenerationService` owns
procedural item generation.

## Services

`RecipeQueryService`, `CraftValidationService`, `CraftExecutionService`,
`MaterialProcessingService`, `CraftingStationService`.

## Tests

Exact selection, wrong station, quantity, concurrent double craft,
deterministic seed, quality, composition, idempotency.
