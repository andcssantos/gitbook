# Evolvaxe --- Timed Expedition Module

## Purpose

A short active session generated from a POI. Default initial duration is
60 seconds.

## Expedition Instance

Store public ID, player, POI, Expedition seed, layout seed, generation
version, `started_at`, `ends_at`, `ended_at`, status, duration, last
event sequence, and settlement status.

Statuses: `CREATED`, `ACTIVE`, `ENDING`, `COMPLETED`, `FAILED`,
`ABANDONED`, `EXPIRED`.

## Authoritative Timer

Final duration = POI base duration + permanent player bonus + valid
temporary modifiers. Server stores `ends_at`. Client timer is visual
only.

## Temporary Map

Generated from POI seed, layout generator, biome, difficulty, and
content version. Contains spawn, resource sources, enemies, chests,
obstacles, and optional events.

## Event Stream

Important actions receive ordered sequence numbers: resource
hit/depleted, item collected, enemy damaged/killed, chest opened, item
discarded, Expedition ended.

## Reload Recovery

On reload, fetch active Expedition, compare server time, restore
persisted/reconstructable state, resume if time remains, otherwise
settle. Reload never resets time.

## Settlement

Collected Expedition loot is reserved and settled idempotently into
player-controlled containers when the Expedition resolves under valid
rules.

## Services

`ExpeditionCreationService`, `ExpeditionStateService`,
`ExpeditionActionService`, `ExpeditionSettlementService`,
`ExpeditionRecoveryService`.

## Tests

Deadline authority, upgrade duration, reload recovery, late action
rejection, idempotent settlement, no duplicate loot.
