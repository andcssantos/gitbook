# Evolvaxe — Security and Server Authority Rules

## Purpose

This document defines the security model for Evolvaxe.

Cursor must follow this file before implementing any gameplay feature that can affect progression, rewards, inventory, crafting, combat, Expedition time, currency, Marketplace, or player ownership.

The central rule is:

> The browser displays the game. The server governs the game.

Everything running in JavaScript can be modified by the player through DevTools, browser extensions, local storage, request replay, or direct API calls.

Therefore, the backend must never trust the client as the source of truth.

---

# 1. Absolute Security Principle

The client may request an action.

The server decides whether the action is valid.

The client must never be allowed to directly declare authoritative results.

Allowed client requests:

- I want to start this Expedition.
- I want to attack this enemy.
- I want to hit this resource node.
- I want to collect this dropped item.
- I want to move this inventory item to x/y.
- I want to craft this recipe using these material instances.
- I want to list this item on the Marketplace.
- I want to buy this listing.

Forbidden client authority:

- I killed this enemy.
- I dealt 500 damage.
- I received this rare item.
- I increased my timer.
- I created this sword with these stats.
- I gained 10,000 coins.
- I moved an item without server validation.
- I changed item quality.
- I changed item properties.
- I changed Expedition results.

---

# 2. Server-Authoritative Systems

The following systems must be fully server-authoritative:

- Authentication and session.
- Player progression.
- World access.
- POI generation.
- Expedition start and end time.
- Expedition actions.
- Combat damage.
- Enemy death.
- Resource extraction.
- Loot generation.
- Chest rewards.
- Inventory placement.
- Container ownership.
- Equipment changes.
- Crafting input consumption.
- Crafted item generation.
- Item properties.
- Item durability.
- Currency minting.
- Wallet balance.
- Marketplace listing.
- Marketplace purchase.
- Upgrade purchase.
- Audit and anti-abuse logs.

The frontend may render predictions or animations, but the persisted result comes only from the server.

---

# 3. Expedition Timer Security

## Wrong Approach

Do not let JavaScript decide whether the player still has time.

Bad pattern:

```js
if (clientTimer > 0) {
  collectItem();
}
```

The player can change `clientTimer` in the console.

## Correct Approach

When an Expedition starts, the server stores:

- `started_at`
- `ends_at`
- `duration_seconds`
- `status`

Every Expedition action must validate:

1. Expedition exists.
2. Expedition belongs to the authenticated player.
3. Expedition status is ACTIVE.
4. Current server time is less than or equal to `ends_at`.
5. Requested action is valid for current Expedition state.

If server time is greater than `ends_at`, reject the action and trigger settlement/recovery.

The frontend timer is only a visual countdown.

---

# 4. Expedition Action API

All Expedition actions should go through a controlled endpoint.

Example:

`POST /api/expeditions/{publicId}/actions`

Request body examples:

```json
{
  "action_type": "RESOURCE_HIT",
  "entity_key": "tree_004",
  "equipped_item_public_id": "ITEM_ABC"
}
```

```json
{
  "action_type": "ATTACK_ENEMY",
  "entity_key": "wolf_002",
  "weapon_item_public_id": "ITEM_XYZ"
}
```

Do not accept reward payloads from the client.

The server calculates the outcome.

---

# 5. Resource Extraction Security

Client may send:

- resource entity key;
- equipped tool ID;
- action intent.

Client must not send:

- drop item ID;
- quantity;
- quality;
- rare success;
- extraction result.

Server validates:

1. Expedition ownership.
2. Expedition active time.
3. Resource entity exists.
4. Resource entity is active.
5. Tool belongs to player.
6. Tool is equipped or valid for the action.
7. Tool family matches required source family.
8. Tool power is sufficient.
9. Resource health can be reduced.
10. Durability can be consumed.
11. Drop can be generated only after valid depletion.

Server calculates:

- resource damage;
- depletion;
- drops;
- quantity;
- exact quality;
- quality bucket;
- material origin;
- rare roll;
- durability loss.

---

# 6. Combat Security

Client may send:

- attack intent;
- target entity key;
- weapon item ID;
- input timestamp for latency tolerance.

Client must not send:

- final damage;
- critical hit result;
- enemy death;
- loot result.

Server validates:

1. Expedition ownership and active time.
2. Enemy exists and is alive.
3. Weapon belongs to player and is equipped.
4. Attack cooldown permits action.
5. Target is within valid range or plausibility window.
6. Player state allows attack.
7. Enemy state allows damage.

Server calculates:

- hit validity;
- damage;
- critical result;
- enemy health;
- death;
- reward generation.

---

# 7. Inventory and GridStack Security

GridStack is only a UI interaction layer.

Client may send:

- item ID;
- source container ID;
- target container ID;
- target x;
- target y;
- expected placement version.

Client must not decide that placement is valid.

Server validates:

1. Player owns or controls source container.
2. Player owns or controls target container.
3. Item exists in source container.
4. Item belongs to player or valid temporary context.
5. Target x/y is inside grid.
6. Item width/height fits.
7. No overlap with other items.
8. Container accepts the item family.
9. Container nesting rule is valid.
10. Expected placement version matches current state.

If validation fails, return the authoritative layout.

Never store pixel values.

---

# 8. Bag and Container Security

Backpacks are item instances that can expose container instances.

MVP rules:

- A backpack must be empty before unequipping.
- A backpack cannot contain another container item.
- `is_container = true` items cannot be placed inside backpack containers.
- All container access must be validated server-side.
- The player cannot open or move items from a container they do not own/control.

Market escrow and market delivery containers are controlled by the server.

---

# 9. Crafting Security

Client may send:

- recipe ID;
- station ID;
- exact selected material instance IDs;
- selected quantities.

Client must not send:

- final item stats;
- final item properties;
- item quality result;
- item name;
- craft seed;
- item value.

Server validates:

1. Player owns selected materials.
2. Selected materials are in accessible containers.
3. Quantities are sufficient.
4. Materials match recipe input rules.
5. Station exists and is valid.
6. Player can use station.
7. Proficiency requirements are met.
8. Craft operation idempotency key is valid.

Server then:

1. Locks input rows.
2. Creates Crafting Event.
3. Generates server-side craft seed.
4. Consumes materials.
5. Calculates output.
6. Creates item instance.
7. Creates item properties.
8. Persists provenance.
9. Places output in valid container or output queue.
10. Commits transaction.

Craft results must not reroll on refresh.

---

# 10. Marketplace Security

Client may request:

- create listing;
- cancel listing;
- buy listing.

Client must not directly transfer items or coins.

## Listing

Server validates:

1. Seller owns item.
2. Item is tradeable.
3. Item is not equipped.
4. Item is not already listed.
5. Item is moved into market escrow.
6. Listing fee is paid if required.

## Purchase

Server validates and executes atomically:

1. Listing is active.
2. Listing version matches.
3. Buyer has sufficient funds.
4. Buyer is allowed to purchase.
5. Wallet debit occurs.
6. Fee sink occurs.
7. Seller credit occurs.
8. Item ownership transfers.
9. Item enters market delivery container.
10. Transaction record is created.
11. Listing status updates.

Use database transactions and row locks.

---

# 11. Currency and Wallet Security

Never trust client-provided currency amounts.

Wallet changes can only occur through server-side domain services.

Every mutation creates a ledger entry.

Examples:

- MINT
- MARKET_PURCHASE
- MARKET_SALE
- MARKET_FEE
- UPGRADE_PURCHASE
- REPAIR
- ADMIN_ADJUSTMENT

Wallet cached balance must be reconcilable with the ledger.

Use integer values only.

Never use FLOAT or DOUBLE for currency.

---

# 12. Request Protection

Every state-changing request must use:

- authenticated session;
- CSRF protection or equivalent same-site protection;
- method validation;
- request validation;
- ownership validation;
- idempotency key where retry or duplication can matter;
- rate limiting where abuse is plausible.

Important endpoints should reject unauthenticated JSON requests.

---

# 13. Rate Limiting and Cooldowns

Apply rate limits or server-side cooldown checks to:

- Expedition actions;
- resource hits;
- enemy attacks;
- inventory moves;
- crafting requests;
- market listing creation;
- market purchase attempts;
- login attempts.

Do not rely only on frontend button disabling.

---

# 14. Anti-Cheat Event Logging

Log suspicious events without immediately breaking normal play unless the action is clearly invalid.

Examples:

- action after Expedition expiration;
- repeated impossible attack cadence;
- repeated out-of-range attacks;
- repeated invalid inventory moves;
- repeated stale placement versions;
- listing item not owned;
- purchase replay;
- duplicate craft idempotency key;
- abnormal rare drop rates;
- impossible item properties;
- wallet reconciliation mismatch.

Use audit logs and anomaly counters.

---

# 15. Idempotency

Idempotency is required for:

- Expedition start;
- Expedition settlement;
- resource depletion reward;
- enemy death reward;
- chest opening;
- craft confirmation;
- currency minting;
- market listing;
- market purchase;
- upgrade purchase.

A duplicate request with the same idempotency key must not duplicate rewards, items, or currency.

---

# 16. Database Transaction Rules

Use transactions for:

- crafting;
- item transfer;
- inventory move between containers;
- equipping/unequipping containers;
- Expedition settlement;
- Marketplace listing;
- Marketplace purchase;
- currency minting;
- upgrade purchase.

Use row locks when ownership, listing status, wallet balance, or item location can change concurrently.

---

# 17. Frontend Rules

Frontend may:

- animate;
- preview;
- display timer;
- show predicted state;
- send player intent;
- render GridStack;
- show local UI feedback.

Frontend may not:

- decide rewards;
- decide item stats;
- decide currency;
- decide if a late Expedition action is valid;
- decide if a craft succeeded;
- decide if an item move is final;
- decide Marketplace settlement.

After every important mutation, frontend should accept authoritative server response and reconcile UI state.

---

# 18. PHP Implementation Pattern

Controllers should be thin.

Controller responsibilities:

- require authentication;
- validate request shape;
- call application service;
- return response.

Service responsibilities:

- enforce domain rules;
- validate ownership;
- validate timing;
- coordinate repositories;
- execute transactions;
- publish events.

Repository responsibilities:

- query and persist data;
- lock rows when requested;
- never contain game rule formulas.

Do not place core game logic directly in JavaScript or PHP view templates.

---

# 19. Minimum Secure Endpoint Checklist

Before implementing any state-changing endpoint, answer:

1. Does the endpoint require authentication?
2. Does it require CSRF protection?
3. Does it validate ownership?
4. Does it validate server time?
5. Does it validate current entity state?
6. Does it use idempotency?
7. Does it use a transaction?
8. Does it lock rows that can be concurrently changed?
9. Does it reject client-provided authoritative results?
10. Does it write audit or ledger records when needed?
11. Does it return authoritative state to the client?

If any answer is no, explain why before implementing.

---

# 20. Cursor Instruction

When Cursor implements any gameplay endpoint, it must explicitly state:

- what data the client may send;
- what data the client may not send;
- what the server validates;
- what the server calculates;
- what tables are changed;
- what transaction boundaries are used;
- what idempotency protection is used;
- what tests prove the client cannot cheat.

If Cursor cannot identify these points, it must not implement the endpoint yet.
