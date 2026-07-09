# Evolvaxe Game Documentation

## Reading Order

1.  `00-game-vision.md`
2.  `01-database-model.md`
3.  `18-cursor-development-rules.md`
4.  Target module document

## Recommended Implementation Order

1.  Database foundation
2.  Player
3.  Items
4.  Inventory
5.  Bags and containers
6.  Worlds
7.  POIs
8.  Expeditions
9.  Resource sources
10. Combat
11. Crafting
12. Procedural item generation
13. Currency
14. Marketplace
15. Upgrades
16. Audit and economic monitoring

Do not implement Marketplace before item ownership, inventory transfer,
wallet ledger, and idempotency are stable.
