# Evolvaxe --- Combat Module

## Purpose

Combat provides access to biological, monster-specific, and rare
production materials.

## MVP Combat

Top-down movement, directional basic attack, attack interval, enemy
aggro, health, defense, critical chance/damage, movement speed, and
conditional damage properties.

## Player Calculated Stats

Maximum health, defense, min/max damage, attack speed, critical chance,
critical damage, movement speed. Conditional properties include damage
against beasts/undead and elemental effects.

## Enemy Definition

Store code, name, family, hierarchy, base health/damage/defense,
movement speed, aggro radius, attack range/interval, reward table,
visual asset, behavior profile, and content version.

## Server Authority

Client may simulate visuals. Server validates cadence, plausible range,
weapon ownership, Expedition deadline, enemy state, and reward creation.
Never accept `enemyKilled=true` as authoritative.

## Rewards

Enemies generate meat, leather, bone, fang, glands, essence, and
monster-specific components. Gold Coin is not a default enemy drop.

## Services

`CombatStateService`, `AttackValidationService`,
`DamageCalculationService`, `EnemyRewardService`.

## Tests

Attack cadence, duplicate death, range, equipment influence, conditional
damage, deterministic rewards, timeout blocking.
