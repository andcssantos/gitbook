# Evolvaxe --- Procedural Item Generation Module

## Goal

Generate more than 10,000 meaningful craft outcomes from combinatorial
rules.

## Pipeline

Load output definition → generation profile → material quality → family
influence → origin influence → proficiency distribution → station
modifiers → deterministic RNG → base attributes → property count →
eligible pool → weighted property selection → property values → negative
properties when eligible → constraint validation → descriptive name →
persist and freeze.

## Generation Profiles

Sword profile may generate damage, speed, durability, critical,
target-family damage, elemental properties, and health.

Pickaxe profile may generate extraction power, efficiency, gathering
speed, durability, rare mineral discovery, quality preservation, and
yield.

## Quality

Quality shifts probability distributions. High quality raises expected
percentile and reduces severe negative variance, but does not guarantee
perfection. Distribution overlap is required.

## Proficiency

Do not use flat `+level` stats. Proficiency reduces lower-tail
probability, improves consistency, slightly improves median, and
increases positive-tail/property weighting.

## Property Constraints

Support eligibility, mutual exclusion, max occurrences, material/origin
requirements, proficiency requirements, and positive/negative conflicts.

## Determinism

All rolls use a seeded RNG abstraction. Never use uncontrolled random
functions. Persist final output.

## Versioning

Store generation, formula, and property-pool versions. Balance changes
affect future crafts unless an explicit migration changes old items.

## Marketplace Search

Properties marked `market_filterable` must support numeric filters.

## Statistical Validation

Simulate at least 10,000 crafts per quality/proficiency scenario.
Validate distributions, overlap, impossible-property absence, inflation,
and seed reproducibility.

## Services

`ItemGenerationService`, `GenerationProfileService`,
`PropertyPoolService`, `GenerationRngService`, `ItemNamingService`.
