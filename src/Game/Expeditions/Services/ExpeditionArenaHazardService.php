<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationPlayerModifiersService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Support\DB;
use PDO;

class ExpeditionArenaHazardService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?ExplorationPlayerModifiersService $modifiers = null,
        private ?PlayerAttributeService $attributes = null,
        private ?ExpeditionStateService $state = null,
        private ?InventoryStateService $inventoryState = null
    ) {
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->modifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->state ??= new ExpeditionStateService($this->pdo);
        $this->inventoryState ??= new InventoryStateService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function rollOnMove(array $expedition, int $playerId, string $biomeCode, float $mapX, float $mapY): array
    {
        if (!$this->tableExists('expedition_arena_vitals')) {
            return $this->noHazardResult($expedition, $playerId);
        }

        $biome = $this->catalog->biome($biomeCode) ?? [];
        $softPenalties = $this->softPenaltiesFromExpedition($expedition);
        $hazardMultiplier = max(1.0, (float) ($softPenalties['hazard_multiplier'] ?? 1.0));
        $hasSoftExposure = $softPenalties !== [];

        $baseChance = (float) ($biome['move_trap_chance'] ?? 0.0) * $hazardMultiplier;
        $playerModifiers = $this->modifiers->forPlayer($playerId, $biomeCode);
        $trapReduction = (float) ($playerModifiers['trap_chance_reduction'] ?? 0);
        $combatBonuses = (array) ($playerModifiers['combat_bonuses'] ?? []);
        $effectiveChance = max(0.0, min(0.55, $baseChance - ($trapReduction * 0.65)));

        $seed = (string) ($expedition['expedition_seed'] ?? 'seed');
        $rng = new ExpeditionArenaRng($seed . ':hazard:' . round($mapX, 1) . ':' . round($mapY, 1));

        $events = [];
        $totalDamage = 0;
        $trapType = null;
        $vitals = $this->vitals((int) $expedition['id'], $playerId);
        $defenseProfile = $this->playerDefenseProfile($playerId);
        $statsByCode = $defenseProfile['stats_by_code'];

        if ($effectiveChance > 0 && $rng->rollChance($effectiveChance)) {
            $defense = $defenseProfile['defense'];
            $damageReduction = max(
                (float) ($combatBonuses['damage_reduction'] ?? 0),
                (float) $defenseProfile['damage_reduction']
            );
            $baseDamage = $rng->rangeInt(
                (int) ($biome['move_trap_damage_min'] ?? 6),
                (int) ($biome['move_trap_damage_max'] ?? 14)
            );
            $baseDamage = (int) round($baseDamage * $hazardMultiplier);
            $damage = max(1, (int) round($baseDamage - ($defense * 0.25) - ($baseDamage * $damageReduction)));
            $vitals['current_hp'] = max(0, (int) $vitals['current_hp'] - $damage);
            $totalDamage += $damage;
            $trapType = $rng->rollChance(0.5) ? 'snare_trap' : 'needle_trap';
            $events[] = [
                'type' => 'arena_trap',
                'message' => $trapType === 'needle_trap'
                    ? 'Uma armadilha escondida no chao te atingiu!'
                    : 'Um laco de arame disparou enquanto voce atravessava a area.',
                'damage' => $damage,
                'target' => 'player',
                'trap_type' => $trapType,
            ];
        }

        if ($hasSoftExposure) {
            $envChance = min(0.40, 0.14 * $hazardMultiplier);
            if ($rng->rollChance($envChance)) {
                $env = $this->environmentalDamageForBiome($biomeCode, $softPenalties, $statsByCode, $rng);
                if ($env['damage'] > 0) {
                    $vitals['current_hp'] = max(0, (int) $vitals['current_hp'] - (int) $env['damage']);
                    $totalDamage += (int) $env['damage'];
                    $events[] = $env['event'];
                }
            }
        }

        if ($events === []) {
            return $this->noHazardResult($expedition, $playerId);
        }

        $this->pdo()->prepare('UPDATE expedition_arena_vitals SET current_hp = :current_hp, updated_at = CURRENT_TIMESTAMP WHERE expedition_instance_id = :expedition_id')
            ->execute([
                'current_hp' => (int) $vitals['current_hp'],
                'expedition_id' => (int) $expedition['id'],
            ]);

        $playerDefeated = false;
        $failure = null;
        if ((int) $vitals['current_hp'] <= 0) {
            $playerDefeated = true;
            $events[] = [
                'type' => 'player_defeat',
                'message' => 'Voce foi derrotado pelos perigos do terreno e a expedicao foi encerrada.',
                'damage' => 0,
                'target' => 'player',
            ];
            $failedRow = $this->state->failActiveForPlayer($playerId, 'arena_hazard');
            if ($failedRow !== null) {
                $metadata = $this->parseJson($failedRow['metadata_json'] ?? null);
                $failure = is_array($metadata['failure'] ?? null) ? $metadata['failure'] : null;
            }
        }

        return [
            'triggered' => true,
            'events' => $events,
            'damage' => $totalDamage,
            'trap_type' => $trapType,
            'trap_chance' => round($effectiveChance, 3),
            'soft_exposure' => $hasSoftExposure,
            'player_defeated' => $playerDefeated,
            'expedition_failed' => $failure,
            'vitals' => [
                'current_hp' => (int) $vitals['current_hp'],
                'max_hp' => (int) $vitals['max_hp'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $softPenalties
     * @param array<string, float> $statsByCode
     * @return array{damage: int, event: array<string, mixed>}
     */
    private function environmentalDamageForBiome(string $biomeCode, array $softPenalties, array $statsByCode, ExpeditionArenaRng $rng): array
    {
        $label = (string) ($softPenalties['label'] ?? 'Desprotegido');
        $kind = match (true) {
            str_contains($biomeCode, 'pantano') || str_contains($biomeCode, 'costa') => 'poison',
            str_contains($biomeCode, 'gel') || str_contains($biomeCode, 'frio') => 'cold',
            str_contains($biomeCode, 'brasa') || str_contains($biomeCode, 'desfiladeiro') => 'heat',
            default => 'exposure',
        };

        $resistStat = match ($kind) {
            'poison' => 'poison_resist',
            'cold' => 'cold_resist',
            'heat' => 'heat_resist',
            default => '',
        };
        $resist = $resistStat !== '' ? max(0.0, (float) ($statsByCode[$resistStat] ?? 0)) : 0.0;
        $base = $rng->rangeInt(3, 8);
        $damage = max(1, (int) round(($base * max(1.0, (float) ($softPenalties['hazard_multiplier'] ?? 1.0))) - ($resist * 1.5)));

        $message = match ($kind) {
            'poison' => "Miasma toxico te afeta ({$label}). Prepare protecao na proxima vez.",
            'cold' => "O frio extremo drenou sua resistencia ({$label}).",
            'heat' => "O calor abrasador te queima ({$label}).",
            default => "O terreno hostil te machuca ({$label}).",
        };

        return [
            'damage' => $damage,
            'event' => [
                'type' => 'soft_entry_dot',
                'message' => $message,
                'damage' => $damage,
                'target' => 'player',
                'exposure_kind' => $kind,
            ],
        ];
    }

    /** @param array<string, mixed> $expedition */
    /** @return array<string, mixed> */
    private function softPenaltiesFromExpedition(array $expedition): array
    {
        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $entry = is_array($metadata['entry_requirements'] ?? null) ? $metadata['entry_requirements'] : [];
        if (($entry['met'] ?? true) === true) {
            return [];
        }
        $penalties = is_array($entry['soft_penalties'] ?? null) ? $entry['soft_penalties'] : [];

        return $penalties;
    }

    /** @return array<string, mixed> */
    private function noHazardResult(array $expedition, int $playerId): array
    {
        $vitals = $this->vitals((int) $expedition['id'], $playerId);

        return [
            'triggered' => false,
            'events' => [],
            'damage' => 0,
            'player_defeated' => false,
            'expedition_failed' => null,
            'vitals' => [
                'current_hp' => (int) $vitals['current_hp'],
                'max_hp' => (int) $vitals['max_hp'],
            ],
        ];
    }

    /** @return array{current_hp: int, max_hp: int} */
    private function vitals(int $expeditionId, int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT current_hp, max_hp FROM expedition_arena_vitals WHERE expedition_instance_id = :expedition_id AND player_id = :player_id LIMIT 1');
        $stmt->execute(['expedition_id' => $expeditionId, 'player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['current_hp' => 100, 'max_hp' => 100];
        }

        return [
            'current_hp' => (int) $row['current_hp'],
            'max_hp' => (int) $row['max_hp'],
        ];
    }

    /** @return array{defense: float, damage_reduction: float, stats_by_code: array<string, float>} */
    private function playerDefenseProfile(int $playerId): array
    {
        $byCode = [];
        try {
            $snapshot = $this->inventoryState->combatSnapshotForPlayer($playerId);
            foreach ($snapshot['character_stats'] ?? [] as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
            $equipmentArmor = max(0.0, (float) (($snapshot['player_power']['armor'] ?? 0)));
        } catch (\Throwable) {
            $equipmentArmor = 0.0;
            $this->attributes->ensureDefaults($playerId);
            foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
        }

        $defense = max(1.0, (float) ($byCode['defense'] ?? 5));
        $armor = max(0.0, (float) ($byCode['armor'] ?? 0)) + max(0.0, $equipmentArmor - $defense);
        $vitality = max(0.0, (float) ($byCode['vitality'] ?? 0));
        $defenseTotal = $defense + ($armor * 0.7);
        $damageReduction = min(0.45, ($armor * 0.004) + ($vitality * 0.0025));

        return [
            'defense' => $defenseTotal,
            'damage_reduction' => $damageReduction,
            'stats_by_code' => $byCode,
        ];
    }

    private function parseJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
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
