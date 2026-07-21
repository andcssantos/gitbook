<?php

namespace App\Game\Player\Services;

use App\Game\Exploration\Services\ExplorationPassiveConstellationService;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Missions\Services\MissionService;
use App\Support\DB;
use PDO;

class PlayerHudService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?PlayerAttributeService $attributes = null,
        private ?PlayerVitalsService $vitals = null
    ) {
        $this->attributes ??= new PlayerAttributeService($pdo);
        $this->vitals ??= new PlayerVitalsService($pdo, $this->attributes);
    }

    public function forPlayer(int $playerId, ?array $power = null): array
    {
        $player = $this->loadPlayer($playerId);
        $attributes = $this->attributes->listForPlayer($playerId);
        $byCode = [];
        foreach ($attributes as $attribute) {
            $byCode[(string) $attribute['code']] = (float) $attribute['value'];
        }

        $level = max(1, (int) ($player['level'] ?? 1));
        $experience = max(0, (int) ($player['experience'] ?? 0));
        $xpNext = $this->playerXpForNextLevel($level);
        $attrLife = (int) round(100 + ($level * 8) + (($byCode['defense'] ?? 0) * 2));
        $gearLife = is_array($power) ? max(0, (int) ($power['life'] ?? 0)) : 0;
        $maxHealth = $attrLife + $gearLife;
        $equipmentTotal = is_array($power) ? max(0, (int) ($power['equipment_total'] ?? 0)) : 0;
        $attributeTotal = (int) round(
            ($byCode['strength'] ?? 0) * 2.4
            + ($byCode['defense'] ?? 0) * 2.0
            + ($byCode['agility'] ?? 0) * 1.4
            + ($byCode['energy'] ?? 0) * 1.1
        );
        if (is_array($power) && isset($power['attribute_total'])) {
            $attributeTotal = max(0, (int) $power['attribute_total']);
        }
        $totalPower = is_array($power) && isset($power['total'])
            ? max(0, (int) $power['total'])
            : ($equipmentTotal + $attributeTotal);
        if ($totalPower <= 0) {
            $totalPower = $attributeTotal;
        }

        $attack = (int) ($power['attack'] ?? round($byCode['strength'] ?? 0));
        $armor = (int) ($power['armor'] ?? round($byCode['defense'] ?? 0));
        $agility = (int) round($byCode['agility'] ?? ($power['agility'] ?? 0));

        $constellationBuffs = array_map(static fn (array $entry): array => [
            'code' => (string) ($entry['code'] ?? ''),
            'label' => (string) ($entry['name'] ?? ''),
            'summary' => (string) ($entry['summary'] ?? ''),
            'source' => 'constellation',
        ], (new ExplorationPassiveConstellationService($this->attributes))->activeForPlayer($playerId));

        $vitals = $this->vitals->snapshot($playerId);
        foreach ((array) ($vitals['active_buffs'] ?? []) as $buff) {
            if (!is_array($buff)) {
                continue;
            }
            $constellationBuffs[] = [
                'code' => (string) ($buff['code'] ?? ''),
                'label' => (string) ($buff['label'] ?? 'Buff'),
                'summary' => 'Buff de comida/sustento',
                'source' => 'food',
                'expires_at' => $buff['expires_at'] ?? null,
            ];
        }

        $missionService = new MissionService($this->pdo);
        try {
            $missionService->syncAll($playerId);
        } catch (\Throwable) {
            // HUD nao deve quebrar se missao falhar
        }
        $missionTracker = $missionService->trackerForPlayer($playerId, 3);

        return [
            'player' => [
                'public_id' => (string) ($player['public_id'] ?? ''),
                'name' => (string) ($player['name'] ?? 'Jogador'),
                'avatar_key' => (string) ($player['avatar_key'] ?? 'wanderer'),
                'level' => $level,
                'experience' => $experience,
                'experience_next' => $xpNext,
                'experience_ratio' => $xpNext > 0 ? round(min(1, $experience / $xpNext), 4) : 0.0,
            ],
            'vitals' => [
                'health' => ['current' => $maxHealth, 'max' => $maxHealth],
                'energy' => $vitals['energy'] ?? ['current' => 0, 'max' => 0],
                'hunger' => $vitals['hunger'] ?? ['current' => 100, 'max' => 100],
                'thirst' => $vitals['thirst'] ?? ['current' => 100, 'max' => 100],
                'resting_until' => $vitals['resting_until'] ?? null,
                'is_resting' => (bool) ($vitals['is_resting'] ?? false),
            ],
            'power' => [
                'total' => $totalPower,
                'equipment_total' => $equipmentTotal,
                'attribute_total' => $attributeTotal,
                'attack' => $attack,
                'armor' => $armor,
                'life' => $maxHealth,
                'agility' => $agility,
            ],
            'attributes' => $attributes,
            'unspent_attribute_points' => $this->attributes->unspentPoints($playerId),
            'attribute_reset_count' => $this->attributes->resetCount($playerId),
            'next_reset_gold_cost' => $this->attributes->nextResetGoldCost($playerId),
            'buffs' => $constellationBuffs,
            'missions' => $missionTracker,
            'wallets' => (new PlayerCurrencyService($this->pdo))->walletsForPlayer($playerId),
        ];
    }

    private function loadPlayer(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function playerXpForNextLevel(int $level): int
    {
        return 500 + (($level - 1) * 180);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
