# Evolvaxe --- Currency and Economy Module

## Currency Origin

Gold-bearing resource → processed Gold → Gold Ingot → Minting Forge →
Gold Coins. Exact recipes are data-driven.

## Wallet

One wallet per player/currency with cached BIGINT balance and version.

## Ledger

Every mutation creates an immutable ledger entry with wallet, signed
amount, balance after, type, reference, idempotency key, and timestamp.

Types include `MINT`, `MARKET_PURCHASE`, `MARKET_SALE`, fees, World
unlock, POI/Inventory/Station upgrade, repair, and admin adjustment.

## Minting

Consume materials → validate Minting Forge → create mint event → credit
wallet → create `MINT` ledger entry. Never trust client-provided coin
amount.

## Sinks

World unlocks, Expedition time upgrades, inventory upgrades, Marketplace
fees, station upgrades, repairs, analysis, and future reforging.

## Monitoring

Track total minted, destroyed, wallet supply, transaction volume,
material price medians, and velocity proxies. The system observes player
prices; it does not directly assign them.

## Settlement

Marketplace buyer debit, fee sink, and seller credit occur atomically.

## Services

`WalletService`, `LedgerService`, `MintingService`,
`EconomyMetricsService`.

## Tests

No invalid negative balance, atomic settlement, idempotent mint,
reconciliation, fee sink, concurrent spend protection.
