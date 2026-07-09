# Evolvaxe --- Logs, Audit and Observability

## Application Logs

Structured logs for failures, exceptions, jobs, latency, and database
errors. Include correlation ID. Never log passwords or secrets.

## Game Audit

Append important mutations: item created/transferred/destroyed, craft
completed, Expedition settled, wallet minted, Market listed/purchased,
upgrade purchased, admin adjustment.

Audit rows include occurrence time, player, event/entity type, entity
public ID, correlation ID, optional idempotency key, source, summary,
and JSON snapshot payload.

## Financial History

Wallet ledger is authoritative. Generic audit logs never replace it.

## Partitioning

Consider date partitioning only for high-volume append tables such as
`game_audit_logs`, `expedition_events`, and `market_events`, after
validating MySQL/MariaDB constraints and query patterns.

## Metrics

Active Expeditions, completion rate, resources generated, quality
distribution, crafts by recipe, property distribution, items destroyed,
currency minted/sunk, Marketplace volume, listing conversion, inventory
occupancy.

## Alerts

Currency mint spikes, duplicate settlement, ledger mismatch, impossible
properties, unusual rare generation, Marketplace failure spikes.

## Admin Mutations

Require actor, reason, correlation ID, before/after snapshots, and
dedicated ledger entry for currency changes.

## Services

`AuditService`, `EconomyReconciliationService`, `GameMetricsService`,
`AnomalySignalService`.
