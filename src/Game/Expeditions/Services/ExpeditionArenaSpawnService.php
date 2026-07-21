<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationLootRollService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class ExpeditionArenaSpawnService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?ExplorationLootRollService $lootRoll = null,
        private ?PlayerAttributeService $attributes = null,
        private ?InventoryStateService $inventoryState = null,
        private ?ExpeditionRunModifiersService $runModifiers = null
    ) {
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->lootRoll ??= new ExplorationLootRollService();
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->inventoryState ??= new InventoryStateService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
    }

    public function ensureArenaReady(array $expedition, int $playerId, string $biomeCode): void
    {
        if (!$this->tableExists('expedition_encounters')) {
            return;
        }

        $expeditionId = (int) ($expedition['id'] ?? 0);
        if ($expeditionId <= 0) {
            return;
        }

        $this->ensureVitals($expeditionId, $playerId);
        $this->ensureEncounters($expedition, $playerId, $biomeCode);
    }

    private function ensureVitals(int $expeditionId, int $playerId): void
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM expedition_arena_vitals WHERE expedition_instance_id = :expedition_id LIMIT 1');
        $stmt->execute(['expedition_id' => $expeditionId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $maxHp = $this->resolveMaxHp($playerId);
        $this->pdo()->prepare('INSERT INTO expedition_arena_vitals (expedition_instance_id, player_id, current_hp, max_hp) VALUES (:expedition_id, :player_id, :current_hp, :max_hp)')
            ->execute([
                'expedition_id' => $expeditionId,
                'player_id' => $playerId,
                'current_hp' => $maxHp,
                'max_hp' => $maxHp,
            ]);
    }

    private function ensureEncounters(array $expedition, int $playerId, string $biomeCode): void
    {
        $this->replenishEncounters($expedition, $playerId, $biomeCode);
    }

    public function replenishEncounters(array $expedition, int $playerId, string $biomeCode): int
    {
        if (!$this->tableExists('expedition_encounters')) {
            return 0;
        }

        $expeditionId = (int) ($expedition['id'] ?? 0);
        if ($expeditionId <= 0) {
            return 0;
        }

        $biome = $this->catalog->biome($biomeCode);
        if ($biome === null) {
            return 0;
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $combat = is_array($metadata['combat'] ?? null) ? $metadata['combat'] : [];
        if (($combat['boss_active'] ?? false) === true) {
            return 0;
        }

        $pool = array_values((array) ($biome['monster_pool'] ?? []));
        if ($pool === []) {
            return 0;
        }

        $targetCount = max(1, (int) ($biome['monster_spawn_count'] ?? 4));
        // Biomas iniciais: densidade baixa mesmo se o catalogo no DB ainda tiver valores antigos.
        if (in_array($biomeCode, ['bosque_inicial', 'costa_salobra'], true)) {
            $targetCount = min($targetCount, 3);
        }
        $eliteChance = (float) ($biome['monster_elite_chance'] ?? 0.18);
        $rareChance = (float) ($biome['monster_rare_chance'] ?? 0.05);
        $runMods = $this->runModifiers->forPlayer($playerId, $biomeCode, $metadata);
        $targetCount = max(1, (int) round($targetCount * (1 + (float) ($runMods['monster_spawn_bonus'] ?? 0))));
        if (in_array($biomeCode, ['bosque_inicial', 'costa_salobra'], true)) {
            $targetCount = min($targetCount, 4);
        }
        $eliteChance = min(0.55, $eliteChance + (float) ($runMods['monster_elite_chance_bonus'] ?? 0));
        $rareChance = min(0.40, $rareChance + (float) ($runMods['monster_rare_chance_bonus'] ?? 0));
        $activeStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM expedition_encounters WHERE expedition_instance_id = :expedition_id AND status = 'active'");
        $activeStmt->execute(['expedition_id' => $expeditionId]);
        $activeCount = (int) $activeStmt->fetchColumn();

        $combatMode = (string) ($biome['combat_mode'] ?? 'continuous');
        if ($combatMode === 'waves') {
            // So spawn nova wave quando o mapa esta limpo.
            if ($activeCount > 0) {
                return 0;
            }
            $toSpawn = max(1, (int) ($biome['wave_size'] ?? 3));
        } else {
            $missing = max(0, $targetCount - $activeCount);
            // Primeiro fill completo; depois respawna 1 por vez para nao lotar o mapa.
            $toSpawn = $activeCount === 0 ? $missing : min(1, $missing);
        }
        if ($toSpawn === 0) {
            return 0;
        }

        $totalStmt = $this->pdo()->prepare('SELECT COUNT(*) FROM expedition_encounters WHERE expedition_instance_id = :expedition_id');
        $totalStmt->execute(['expedition_id' => $expeditionId]);
        $totalSpawned = (int) $totalStmt->fetchColumn();

        $seed = (string) ($expedition['expedition_seed'] ?? 'seed');
        $width = (float) ($biome['map_width'] ?? 6);
        $height = (float) ($biome['map_height'] ?? 4);
        $spawned = 0;

        for ($i = 0; $i < $toSpawn; $i++) {
            $waveIndex = $totalSpawned + $i;
            $rng = new ExpeditionArenaRng($seed . ':spawn:' . $waveIndex);
            $definitionCode = $pool[$rng->rangeInt(0, count($pool) - 1)];
            $tier = 1;
            if ($rng->rollChance($rareChance)) {
                $tier = 3;
            } elseif ($rng->rollChance($eliteChance)) {
                $tier = 2;
            }

            $scaled = $this->catalog->scaledMonster($definitionCode, $tier);
            $mapX = round(0.8 + ($rng->nextFloat() * max(0.5, $width - 1.6)), 2);
            $mapY = round(0.8 + ($rng->nextFloat() * max(0.5, $height - 1.6)), 2);

            $this->pdo()->prepare('INSERT INTO expedition_encounters (
                public_id, expedition_instance_id, player_id, definition_code, tier,
                map_x, map_y, current_hp, max_hp, status, combat_turn, config_json
            ) VALUES (
                :public_id, :expedition_instance_id, :player_id, :definition_code, :tier,
                :map_x, :map_y, :current_hp, :max_hp, :status, :combat_turn, :config_json
            )')->execute([
                'public_id' => PublicId::uuid(),
                'expedition_instance_id' => $expeditionId,
                'player_id' => $playerId,
                'definition_code' => $definitionCode,
                'tier' => $tier,
                'map_x' => $mapX,
                'map_y' => $mapY,
                'current_hp' => (int) $scaled['max_hp'],
                'max_hp' => (int) $scaled['max_hp'],
                'status' => 'active',
                'combat_turn' => 0,
                'config_json' => json_encode($scaled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $spawned++;
        }

        return $spawned;
    }

    /** @param array<string, mixed> $expedition */
    /** @return array<string, mixed>|null */
    public function spawnBossEncounter(array $expedition, int $playerId, string $biomeCode): ?array
    {
        if (!$this->tableExists('expedition_encounters')) {
            return null;
        }

        $expeditionId = (int) ($expedition['id'] ?? 0);
        if ($expeditionId <= 0) {
            return null;
        }

        $biome = $this->catalog->biome($biomeCode);
        if ($biome === null) {
            return null;
        }

        $pool = array_values((array) ($biome['monster_pool'] ?? []));
        if ($pool === []) {
            return null;
        }

        $existingBoss = $this->pdo()->prepare("SELECT id FROM expedition_encounters
            WHERE expedition_instance_id = :expedition_id AND status = 'active' AND config_json LIKE '%\"is_boss\":true%'
            LIMIT 1");
        $existingBoss->execute(['expedition_id' => $expeditionId]);
        if ($existingBoss->fetchColumn() !== false) {
            return null;
        }

        $seed = (string) ($expedition['expedition_seed'] ?? 'seed');
        $rng = new ExpeditionArenaRng($seed . ':boss:' . $expeditionId);
        $definitionCode = $pool[$rng->rangeInt(0, count($pool) - 1)];
        $scaled = $this->catalog->scaledBoss($definitionCode, (string) ($biome['name'] ?? $biomeCode));
        $width = (float) ($biome['map_width'] ?? 6);
        $height = (float) ($biome['map_height'] ?? 4);
        $mapX = round(($width * 0.55) + (($rng->nextFloat() - 0.5) * 0.8), 2);
        $mapY = round(($height * 0.5) + (($rng->nextFloat() - 0.5) * 0.6), 2);
        $publicId = PublicId::uuid();

        $this->pdo()->prepare('INSERT INTO expedition_encounters (
            public_id, expedition_instance_id, player_id, definition_code, tier,
            map_x, map_y, current_hp, max_hp, status, combat_turn, config_json
        ) VALUES (
            :public_id, :expedition_instance_id, :player_id, :definition_code, :tier,
            :map_x, :map_y, :current_hp, :max_hp, :status, :combat_turn, :config_json
        )')->execute([
            'public_id' => $publicId,
            'expedition_instance_id' => $expeditionId,
            'player_id' => $playerId,
            'definition_code' => $definitionCode,
            'tier' => 3,
            'map_x' => $mapX,
            'map_y' => $mapY,
            'current_hp' => (int) $scaled['max_hp'],
            'max_hp' => (int) $scaled['max_hp'],
            'status' => 'active',
            'combat_turn' => 0,
            'config_json' => json_encode($scaled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return [
            'public_id' => $publicId,
            'definition_code' => $definitionCode,
            'name' => (string) $scaled['name'],
            'sprite_key' => (string) $scaled['sprite_key'],
            'tier' => 3,
            'tier_label' => 'Chefe',
            'map_x' => $mapX,
            'map_y' => $mapY,
            'current_hp' => (int) $scaled['max_hp'],
            'max_hp' => (int) $scaled['max_hp'],
            'is_boss' => true,
        ];
    }

    /** @param list<array<string, mixed>> $lootTable */
    public function spawnGroundLoot(
        int $expeditionId,
        int $playerId,
        float $mapX,
        float $mapY,
        array $lootTable,
        ExpeditionArenaRng $rng,
        float $extraLootBonus = 0.0,
        float $rarityBonus = 0.0,
        float $chestFindBonus = 0.0
    ): ?array {
        if ($lootTable === [] || !$this->tableExists('expedition_ground_loot')) {
            return null;
        }

        $rolls = 1;
        if ($rarityBonus >= 0.25 || $chestFindBonus >= 0.25) {
            $rolls = 2;
        }

        $rolled = $this->lootRoll->roll(
            $lootTable,
            1,
            true,
            $rolls,
            (int) round($rng->nextFloat() * 1000000),
            $extraLootBonus,
            $rarityBonus,
            $chestFindBonus
        );
        if ($rolled === []) {
            return null;
        }

        // Prefere a entrada de maior quantidade (raridade/bonus pode gerar multiplos).
        usort($rolled, static fn (array $a, array $b): int => ((int) ($b['quantity'] ?? 0)) <=> ((int) ($a['quantity'] ?? 0)));
        $entry = $rolled[0];
        $publicId = PublicId::uuid();
        $this->pdo()->prepare('INSERT INTO expedition_ground_loot (
            public_id, expedition_instance_id, player_id, item_definition_code, quantity, map_x, map_y, status
        ) VALUES (
            :public_id, :expedition_instance_id, :player_id, :item_definition_code, :quantity, :map_x, :map_y, :status
        )')->execute([
            'public_id' => $publicId,
            'expedition_instance_id' => $expeditionId,
            'player_id' => $playerId,
            'item_definition_code' => (string) ($entry['item_definition_code'] ?? ''),
            'quantity' => max(1, (int) ($entry['quantity'] ?? 1)),
            'map_x' => $mapX,
            'map_y' => $mapY,
            'status' => 'ground',
        ]);

        return [
            'public_id' => $publicId,
            'item_definition_code' => (string) ($entry['item_definition_code'] ?? ''),
            'quantity' => max(1, (int) ($entry['quantity'] ?? 1)),
            'map_x' => $mapX,
            'map_y' => $mapY,
        ];
    }

    private function resolveMaxHp(int $playerId): int
    {
        $stmt = $this->pdo()->prepare('SELECT level FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);
        $level = max(1, (int) $stmt->fetchColumn());

        $byCode = [];
        try {
            $snapshot = $this->inventoryState->combatSnapshotForPlayer($playerId);
            foreach ($snapshot['character_stats'] ?? [] as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
            $equipmentLife = max(0.0, (float) (($snapshot['player_power']['life'] ?? 0)));
        } catch (\Throwable) {
            $equipmentLife = 0.0;
            $this->attributes->ensureDefaults($playerId);
            foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
        }

        $defense = max(1.0, (float) ($byCode['defense'] ?? 5));
        $vitality = max(0.0, (float) ($byCode['vitality'] ?? 0));
        $maxHealthBonus = max(0.0, (float) ($byCode['max_health'] ?? 0));
        $armor = max(0.0, (float) ($byCode['armor'] ?? 0));

        return (int) max(50, round(
            100
            + ($level * 8)
            + ($defense * 2)
            + ($vitality * 3.5)
            + ($maxHealthBonus * 0.6)
            + ($armor * 0.35)
            + ($equipmentLife * 0.25)
        ));
    }

    private function parseJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
