# Evolvaxe --- Player and Account Module

## Responsibilities

Own account-linked game identity, player profile, global progression
references, and upgrade state.

## Entities

### Account

`id`, `public_id`, `email`, `password_hash`, `status`, `last_login_at`,
timestamps.

### Player

`id`, `public_id`, `account_id`, `display_name`,
`current_world_definition_id`, `status`, timestamps.

### Player Progression

`player_id`, `progression_score`, `unlocked_world_tier`,
`expedition_time_bonus_seconds`, `inventory_upgrade_level`,
`storage_upgrade_level`.

Do not keep all skills in one JSON blob. Activity proficiency belongs to
the Proficiency module.

## Rules

-   Public IDs do not expose sequential DB IDs.
-   Server time is authoritative.
-   Player deletion cannot erase financial history.
-   Starter creation must atomically create player, starter World state,
    main inventory, wallet, and starter progression.

## Services

`PlayerCreationService`, `PlayerProgressionService`,
`PlayerWorldAccessService`, `PlayerUpgradeService`.

## Events

`PlayerCreated`, `PlayerWorldUnlocked`, `PlayerUpgradePurchased`.

## Tests

Starter state, display-name uniqueness, upgrade calculation,
authorization, and atomic creation.
