# Evolvaxe --- Persistence, Save State and Determinism

## Authority

Server state is authoritative for progression, Worlds, POIs, Expedition
deadline/rewards, item ownership, inventory placement, crafting,
equipment, wallet, and Marketplace.

Browser state is presentation/cache.

## Domain Seeds

Use separate `world_seed`, `poi_seed`, `expedition_seed`, `entity_seed`,
and `craft_seed`. Do not reuse one random stream for unrelated domains.

## Seed Security

Do not expose sensitive seeds when prediction could reveal rare
outcomes.

## Idempotency

Mandatory for Expedition start/settlement, crafting, minting,
Marketplace listing/purchase, and upgrade purchase.

## Transactional Outbox

Use an outbox for cross-module events. Commit core transaction first;
workers process analytics, notifications, and aggregates.

## Recovery

On reconnect, fetch active Expedition, compare server time, restore
state, resume if valid, otherwise settle.

## Optimistic Concurrency

Use versions on wallets, listings, and inventory placements/layout state
where useful.

## Services

`IdempotencyService`, `SaveRecoveryService`, `DeterministicSeedService`,
`OutboxPublisher`.

## Tests

Reload, duplicate POST, concurrent tabs, network retry, expired
Expedition recovery, duplicate craft and purchase prevention.
