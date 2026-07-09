# Evolvaxe --- Cursor Development Rules

## Mandatory Reading

Before coding, read `00-game-vision.md`, `01-database-model.md`, this
file, and the target module document.

## Rules

1.  Do not rebuild unrelated modules.
2.  Implement one domain module at a time.
3.  Do not hardcode game content in controllers or UI.
4.  Controllers authenticate, parse, call services, and map responses.
5.  Server is authoritative for rewards, items, currency, and
    Marketplace.
6.  Use deterministic RNG where documented.
7.  Add tests for every economic or ownership mutation.
8.  Use migrations for schema changes.
9.  Use English code identifiers.
10. Never use floating point for currency.
11. Never store GridStack positions in pixels.
12. Do not use JSON as a shortcut for relational gameplay data.
13. Do not create fixed prices for unique items.
14. Do not generate crafting results client-side.
15. Do not allow refresh rerolls.
16. Use transactions for crafting, ownership transfer, purchase,
    minting, upgrades, and Expedition settlement.
17. Static content must be data-driven.
18. GridStack is a visual interaction layer; backend validates every
    move.

## Required Workflow

Before implementation: 1. inspect current codebase; 2. identify affected
modules; 3. provide implementation plan; 4. list migrations; 5. list
services/interfaces; 6. list events; 7. list endpoints; 8. list tests;
9. implement only approved scope.

## Suggested Modules

Identity, Player, World, POI, Expedition, Resource, Combat, Item,
Inventory, Container, Crafting, ItemGeneration, Marketplace, Economy,
Upgrade, Audit.

## Cursor Prompt Template

Read `game-docs/00-game-vision.md`, `game-docs/01-database-model.md`,
`game-docs/18-cursor-development-rules.md`, and `[TARGET DOCUMENT]`.

Analyze the current codebase before modifying files.

Implement only `[MODULE/TASK]`.

First provide an implementation plan containing affected files,
migrations, services, events, endpoints, and tests.

Preserve module boundaries and compatible existing behavior. Do not
implement future modules.

After implementation, summarize architectural decisions, migrations,
tests, and unresolved risks.

## Definition of Done

Schema migrated; relationships explicit; service boundaries exist;
server validation exists; tests pass; important mutations are auditable;
docs remain accurate; no unrelated scope added.
