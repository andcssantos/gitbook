# Evolvaxe --- Resource Sources Module

## Purpose

Trees, rocks, bushes, ores, plants, and special sources share one
extraction architecture.

## Definition

Store code, name, source type, required tool family, minimum extraction
power, base health, drop table, visual asset, collision profile, and
content version.

## Expedition Instance

Store Expedition, entity key, source definition, entity seed, logical
position, current health, state, hidden quality potential, and
extraction count.

In one-minute Expeditions, sources normally do not respawn.

## Tools

A tool defines family, tier, extraction power, extraction efficiency,
gathering speed, rare discovery modifier, and durability.

Better tools can unlock drop entries, preserve quality, improve yield,
improve speed, and alter rare discovery. They must not only reduce hit
count.

## Drop Tables

Use relational `resource_source_drop_tables` and
`resource_source_drop_entries`. Entry fields include material
definition, chance/weight, quantity range, minimum tool tier/power, rare
classification, and quality modifier.

## Quality

Internal decimal 0--100. Display buckets: Poor, Impure, Standard, Fine,
Pure, Pristine. Preserve exact quality.

## Determinism

Reward seed derives from Expedition seed, resource entity seed,
extraction sequence, equipped tool instance, and generation version.
Persist extraction atomically with rewards.

## Services

`ResourceInteractionService`, `ExtractionCalculationService`,
`MaterialGenerationService`, `ResourceDropService`.

## Tests

Wrong tool, insufficient power, tier unlocks, quality preservation,
deterministic reward, durability use, double-depletion prevention.
