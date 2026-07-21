<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationPlayerModifiersService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Player\Services\PlayerTemporaryBuffService;
use App\Support\DB;
use PDO;

/**
 * Pipeline unico de modifiers da run:
 * gear/atributos (snapshot) + buffs temporarios (metadata) + constelacoes/exploracao.
 */
class ExpeditionRunModifiersService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?InventoryStateService $inventoryState = null,
        private ?ExplorationPlayerModifiersService $explorationModifiers = null,
        private ?PlayerTemporaryBuffService $temporaryBuffs = null
    ) {
        $this->inventoryState ??= new InventoryStateService($this->pdo);
        $this->explorationModifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->temporaryBuffs ??= new PlayerTemporaryBuffService($this->pdo);
    }

    /**
     * @param array<string, mixed>|null $expeditionMetadata
     * @return array<string, mixed>
     */
    public function forPlayer(int $playerId, ?string $biomeCode = null, ?array $expeditionMetadata = null): array
    {
        $snapshot = $this->inventoryState->combatSnapshotForPlayer($playerId);
        $statsByCode = $this->indexStats((array) ($snapshot['character_stats'] ?? []));

        $temporary = $this->temporaryBuffs->activeForPlayer($playerId, $expeditionMetadata);
        foreach ((array) ($temporary['stat_bonuses'] ?? []) as $code => $value) {
            $key = (string) $code;
            if ($key === '') {
                continue;
            }
            $statsByCode[$key] = (float) ($statsByCode[$key] ?? 0) + (float) $value;
        }

        $exploration = $this->explorationModifiers->forPlayer($playerId, $biomeCode);
        $combatBonuses = (array) ($exploration['combat_bonuses'] ?? []);

        $itemRarityBonus = $this->pct($statsByCode['item_rarity_bonus'] ?? 0, 1.5);
        $chestFindChance = $this->pct($statsByCode['chest_find_chance'] ?? 0, 1.0);
        $mapDurationBonus = $this->pct($statsByCode['map_duration_bonus'] ?? 0, 1.0);
        $monsterSpawnBonus = $this->pct($statsByCode['monster_spawn_bonus'] ?? 0, 0.75);
        // Raridade de drop tambem empurra um pouco monstros elite/raros.
        $monsterRareChanceBonus = min(
            0.30,
            $this->pct($statsByCode['monster_rare_chance'] ?? 0, 0.30) + ($itemRarityBonus * 0.35)
        );
        $monsterEliteChanceBonus = min(
            0.30,
            $this->pct($statsByCode['monster_elite_chance'] ?? 0, 0.30) + ($itemRarityBonus * 0.20)
        );

        $expeditionLootBonus = (float) ($exploration['expedition_loot_bonus'] ?? 0) + ($itemRarityBonus * 0.5);

        return [
            'combat_snapshot' => $snapshot,
            'stats_by_code' => $statsByCode,
            'player_power' => $snapshot['player_power'] ?? [],
            'exploration_modifiers' => $exploration,
            'temporary_buffs' => $temporary,
            'combat_bonuses' => $combatBonuses,
            'item_rarity_bonus' => $itemRarityBonus,
            'chest_find_chance' => $chestFindChance,
            'map_duration_bonus' => $mapDurationBonus,
            'monster_spawn_bonus' => $monsterSpawnBonus,
            'monster_rare_chance_bonus' => $monsterRareChanceBonus,
            'monster_elite_chance_bonus' => $monsterEliteChanceBonus,
            'trap_chance_reduction' => (float) ($exploration['trap_chance_reduction'] ?? 0),
            'discovery_radius_bonus' => (float) ($exploration['discovery_radius_bonus'] ?? 0),
            'expedition_loot_bonus' => round($expeditionLootBonus, 4),
            'loot_pickup_radius' => max(0.0, min(2.5, (float) ($statsByCode['loot_pickup_radius'] ?? 0))),
            'attack_speed' => max(0.0, (float) ($statsByCode['attack_speed'] ?? 0)),
            'dodge_chance' => max(0.0, (float) ($statsByCode['dodge_chance'] ?? 0)),
        ];
    }

    /**
     * Resumo leve para gravar em metadata da expedicao.
     *
     * @param array<string, mixed>|null $expeditionMetadata
     * @return array<string, mixed>
     */
    public function summaryForMetadata(int $playerId, ?string $biomeCode = null, ?array $expeditionMetadata = null): array
    {
        $mods = $this->forPlayer($playerId, $biomeCode, $expeditionMetadata);

        return [
            'map_duration_bonus' => $mods['map_duration_bonus'],
            'item_rarity_bonus' => $mods['item_rarity_bonus'],
            'chest_find_chance' => $mods['chest_find_chance'],
            'monster_spawn_bonus' => $mods['monster_spawn_bonus'],
            'monster_rare_chance_bonus' => $mods['monster_rare_chance_bonus'],
            'monster_elite_chance_bonus' => $mods['monster_elite_chance_bonus'],
            'expedition_loot_bonus' => $mods['expedition_loot_bonus'],
            'loot_pickup_radius' => $mods['loot_pickup_radius'],
            'active_buff_count' => count((array) ($mods['temporary_buffs']['active_buffs'] ?? [])),
            'potion_belt_count' => count((array) ($mods['temporary_buffs']['potion_belt'] ?? [])),
        ];
    }

    /**
     * @param list<array<string, mixed>> $stats
     * @return array<string, float>
     */
    private function indexStats(array $stats): array
    {
        $byCode = [];
        foreach ($stats as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $code = (string) ($stat['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $byCode[$code] = (float) ($stat['value'] ?? 0);
        }

        return $byCode;
    }

    private function pct(float|int $rawPercent, float $cap): float
    {
        return min($cap, max(0.0, ((float) $rawPercent) / 100));
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
