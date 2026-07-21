<?php

namespace App\Game\Player\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

/**
 * Vitals persistentes do personagem: energia, fome, sede, descanso e buffs de comida.
 */
class PlayerVitalsService
{
    public const MIN_ENERGY_TO_START = 5.0;
    public const MOVE_ENERGY_COST = 0.5;
    public const TICK_COMBAT_ENERGY_COST = 0.35;
    public const TICK_IDLE_ENERGY_COST = 0.1;
    public const TICK_COMBAT_HUNGER_COST = 0.12;
    public const TICK_COMBAT_THIRST_COST = 0.18;
    public const REST_ENERGY_PER_MINUTE = 4.0;

    public function __construct(
        private ?PDO $pdo = null,
        private ?PlayerAttributeService $attributes = null
    ) {
        $this->attributes ??= new PlayerAttributeService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function snapshot(int $playerId): array
    {
        $this->ensureRow($playerId);
        $this->resolvePassiveRecovery($playerId);
        $row = $this->loadRow($playerId);
        $caps = $this->capsForPlayer($playerId);
        $buffs = $this->activeBuffsFromMetadata($row['metadata_json'] ?? null);

        return [
            'energy' => [
                'current' => $this->infiniteEnergyEnabled()
                    ? $caps['energy']
                    : round(min($caps['energy'], max(0, (float) ($row['energy_current'] ?? 0))), 2),
                'max' => $caps['energy'],
            ],
            'hunger' => [
                'current' => round(min($caps['hunger'], max(0, (float) ($row['hunger_current'] ?? 0))), 2),
                'max' => $caps['hunger'],
            ],
            'thirst' => [
                'current' => round(min($caps['thirst'], max(0, (float) ($row['thirst_current'] ?? 0))), 2),
                'max' => $caps['thirst'],
            ],
            'resting_until' => $row['resting_until'] ?? null,
            'is_resting' => $this->isResting($row),
            'active_buffs' => $buffs,
            'energy_cost_reduction' => $this->energyCostReduction($buffs),
            'infinite_energy' => $this->infiniteEnergyEnabled(),
        ];
    }

    public function assertCanStartExpedition(int $playerId): void
    {
        $snap = $this->snapshot($playerId);
        if ($this->isResting($this->loadRow($playerId))) {
            throw new \RuntimeException('Cannot start an expedition while resting.');
        }
        if ($this->infiniteEnergyEnabled()) {
            return;
        }
        if ((float) ($snap['energy']['current'] ?? 0) < self::MIN_ENERGY_TO_START) {
            throw new \RuntimeException('Energia insuficiente para iniciar a expedicao.');
        }
    }

    /**
     * @param array<string, mixed> $softPenalties
     * @return array<string, mixed>
     */
    public function spendEnergy(int $playerId, float $baseCost, array $softPenalties = [], string $reason = 'action'): array
    {
        $snap = $this->snapshot($playerId);
        if ($this->infiniteEnergyEnabled() || $baseCost <= 0) {
            return [
                'spent' => 0.0,
                'reason' => $reason,
                'energy' => [
                    'current' => (float) ($snap['energy']['current'] ?? 0),
                    'max' => (float) ($snap['energy']['max'] ?? 0),
                ],
                'multiplier' => 1.0,
                'infinite' => $this->infiniteEnergyEnabled(),
            ];
        }

        $reduction = (float) ($snap['energy_cost_reduction'] ?? 0);
        $multiplier = max(1.0, (float) ($softPenalties['energy_cost_multiplier'] ?? 1.0));
        $cost = max(0.1, $baseCost * $multiplier * (1 - min(0.75, $reduction)));

        $current = (float) ($snap['energy']['current'] ?? 0);
        if ($current + 0.0001 < $cost) {
            throw new \RuntimeException('Energia insuficiente para esta acao.');
        }

        $newCurrent = max(0, $current - $cost);
        if ($this->tableExists('player_vitals')) {
            $this->updateEnergy($playerId, $newCurrent);
        }

        return [
            'spent' => round($cost, 2),
            'reason' => $reason,
            'energy' => [
                'current' => round($newCurrent, 2),
                'max' => (float) ($snap['energy']['max'] ?? 0),
            ],
            'multiplier' => $multiplier,
        ];
    }

    /**
     * Dreno leve de fome/sede por tick de combate da campanha.
     *
     * @return array{hunger:float,thirst:float}
     */
    public function drainCampaignTick(
        int $playerId,
        float $hungerCost = self::TICK_COMBAT_HUNGER_COST,
        float $thirstCost = self::TICK_COMBAT_THIRST_COST
    ): array {
        $this->ensureRow($playerId);
        $snap = $this->snapshot($playerId);
        $hunger = max(0.0, (float) ($snap['hunger']['current'] ?? 0) - max(0.0, $hungerCost));
        $thirst = max(0.0, (float) ($snap['thirst']['current'] ?? 0) - max(0.0, $thirstCost));

        if ($this->tableExists('player_vitals')) {
            $this->pdo()->prepare('UPDATE player_vitals
                SET hunger_current = :hunger,
                    thirst_current = :thirst,
                    last_resolved_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE player_id = :player_id')
                ->execute([
                    'player_id' => $playerId,
                    'hunger' => $hunger,
                    'thirst' => $thirst,
                ]);
        }

        return [
            'hunger' => round($hunger, 2),
            'thirst' => round($thirst, 2),
        ];
    }

    /**
     * Penalties de campanha/expedicao a partir de fome e sede.
     * Barras cheias = bem alimentado/hidratado.
     *
     * @return array{
     *   hunger_ratio:float,
     *   thirst_ratio:float,
     *   energy_cost_multiplier:float,
     *   player_attack_mult:float,
     *   carry_locked_cols:int,
     *   notes:list<string>
     * }
     */
    public function campaignSoftPenalties(int $playerId): array
    {
        $snap = $this->snapshot($playerId);
        $hungerMax = max(1.0, (float) ($snap['hunger']['max'] ?? 100));
        $thirstMax = max(1.0, (float) ($snap['thirst']['max'] ?? 100));
        $hungerRatio = max(0.0, min(1.0, (float) ($snap['hunger']['current'] ?? 0) / $hungerMax));
        $thirstRatio = max(0.0, min(1.0, (float) ($snap['thirst']['current'] ?? 0) / $thirstMax));

        $energyMult = 1.0;
        if ($thirstRatio < 0.3) {
            $energyMult = 1.55;
        } elseif ($thirstRatio < 0.6) {
            $energyMult = 1.25;
        }

        $attackMult = 1.0;
        $lockedCols = 0;
        if ($hungerRatio < 0.25) {
            $attackMult = 0.82;
            $lockedCols = 2;
        } elseif ($hungerRatio < 0.5) {
            $attackMult = 0.92;
            $lockedCols = 1;
        }

        $notes = [];
        if ($lockedCols > 0) {
            $notes[] = "Fome: -{$lockedCols} colunas na Expedition Carry";
        }
        if ($energyMult > 1.01) {
            $notes[] = 'Sede: energia consome x' . number_format($energyMult, 2);
        }
        if ($attackMult < 0.99) {
            $notes[] = 'Fome: dano reduzido';
        }

        return [
            'hunger_ratio' => round($hungerRatio, 3),
            'thirst_ratio' => round($thirstRatio, 3),
            'energy_cost_multiplier' => $energyMult,
            'player_attack_mult' => $attackMult,
            'carry_locked_cols' => $lockedCols,
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    public function restore(int $playerId, float $energy = 0, float $hunger = 0, float $thirst = 0): array
    {
        $snap = $this->snapshot($playerId);
        $caps = $this->capsForPlayer($playerId);
        $newEnergy = min($caps['energy'], (float) ($snap['energy']['current'] ?? 0) + max(0, $energy));
        $newHunger = min($caps['hunger'], (float) ($snap['hunger']['current'] ?? 0) + max(0, $hunger));
        $newThirst = min($caps['thirst'], (float) ($snap['thirst']['current'] ?? 0) + max(0, $thirst));

        $this->pdo()->prepare('UPDATE player_vitals
            SET energy_current = :energy,
                hunger_current = :hunger,
                thirst_current = :thirst,
                last_resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id')
            ->execute([
                'player_id' => $playerId,
                'energy' => $newEnergy,
                'hunger' => $newHunger,
                'thirst' => $newThirst,
            ]);

        return $this->snapshot($playerId);
    }

    /** @return array<string, mixed> */
    public function startRest(int $playerId, int $durationMinutes = 20): array
    {
        if ($this->hasActiveExpedition($playerId)) {
            throw new \RuntimeException('Leave the expedition before resting.');
        }

        $durationMinutes = max(1, min(120, $durationMinutes));
        $until = date('Y-m-d H:i:s', time() + ($durationMinutes * 60));
        $this->ensureRow($playerId);
        $this->pdo()->prepare('UPDATE player_vitals
            SET resting_until = :until,
                last_resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id')
            ->execute([
                'player_id' => $playerId,
                'until' => $until,
            ]);

        return [
            'resting' => true,
            'duration_minutes' => $durationMinutes,
            'resting_until' => $until,
            'vitals' => $this->snapshot($playerId),
        ];
    }

    /**
     * @param array<string, mixed> $buff
     * @return array<string, mixed>
     */
    public function applyBuff(int $playerId, array $buff): array
    {
        $this->ensureRow($playerId);
        $row = $this->loadRow($playerId);
        $metadata = $this->parseJson($row['metadata_json'] ?? null);
        $active = array_values(array_filter(
            (array) ($metadata['active_buffs'] ?? []),
            static fn (mixed $entry): bool => is_array($entry)
        ));

        $code = (string) ($buff['code'] ?? '');
        if ($code !== '') {
            $active = array_values(array_filter(
                $active,
                static fn (array $entry): bool => (string) ($entry['code'] ?? '') !== $code
            ));
        }

        $active[] = $buff;
        $metadata['active_buffs'] = $active;
        $this->pdo()->prepare('UPDATE player_vitals
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id')
            ->execute([
                'player_id' => $playerId,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

        return $this->snapshot($playerId);
    }

    /** @return array{energy: float, hunger: float, thirst: float} */
    public function capsForPlayer(int $playerId): array
    {
        $byCode = [];
        foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
            $byCode[(string) $attribute['code']] = (float) $attribute['value'];
        }
        $player = $this->loadPlayer($playerId);
        $level = max(1, (int) ($player['level'] ?? 1));

        return [
            'energy' => (float) round(60 + ($level * 5) + (($byCode['energy'] ?? 0) * 3)),
            'hunger' => 100.0,
            'thirst' => 100.0,
        ];
    }

    private function resolvePassiveRecovery(int $playerId): void
    {
        if (!$this->tableExists('player_vitals')) {
            return;
        }

        $row = $this->loadRow($playerId);
        if ($row === []) {
            return;
        }

        $now = time();
        $last = strtotime((string) ($row['last_resolved_at'] ?? '')) ?: $now;
        $elapsedMinutes = max(0, ($now - $last) / 60);
        if ($elapsedMinutes < 0.05) {
            return;
        }

        $caps = $this->capsForPlayer($playerId);
        $energy = (float) ($row['energy_current'] ?? 0);
        $restingUntil = strtotime((string) ($row['resting_until'] ?? '')) ?: 0;
        $stillResting = $restingUntil > $now;

        if ($stillResting || ($restingUntil > 0 && $restingUntil <= $now && $last < $restingUntil)) {
            $recoverUntil = min($now, max($last, $restingUntil));
            $restMinutes = max(0, ($recoverUntil - $last) / 60);
            if ($restMinutes > 0) {
                $energy = min($caps['energy'], $energy + ($restMinutes * self::REST_ENERGY_PER_MINUTE));
            }
        }

        $clearRest = $restingUntil > 0 && $restingUntil <= $now;
        if (!$this->tableExists('player_vitals')) {
            return;
        }
        $this->pdo()->prepare('UPDATE player_vitals
            SET energy_current = :energy,
                resting_until = CASE WHEN :clear_rest = 1 THEN NULL ELSE resting_until END,
                last_resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id')
            ->execute([
                'player_id' => $playerId,
                'energy' => $energy,
                'clear_rest' => $clearRest ? 1 : 0,
            ]);
    }

    private function updateEnergy(int $playerId, float $energy): void
    {
        if (!$this->tableExists('player_vitals')) {
            return;
        }
        $this->pdo()->prepare('UPDATE player_vitals
            SET energy_current = :energy, last_resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id')
            ->execute([
                'player_id' => $playerId,
                'energy' => $energy,
            ]);
    }

    private function ensureRow(int $playerId): void
    {
        if (!$this->tableExists('player_vitals')) {
            return;
        }

        $existing = $this->pdo()->prepare('SELECT id FROM player_vitals WHERE player_id = :player_id LIMIT 1');
        $existing->execute(['player_id' => $playerId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $caps = $this->capsForPlayer($playerId);
        $this->pdo()->prepare('INSERT INTO player_vitals (
            player_id, energy_current, hunger_current, thirst_current, last_resolved_at, metadata_json
        ) VALUES (
            :player_id, :energy, :hunger, :thirst, CURRENT_TIMESTAMP, :metadata_json
        )')->execute([
            'player_id' => $playerId,
            'energy' => $caps['energy'],
            'hunger' => $caps['hunger'],
            'thirst' => $caps['thirst'],
            'metadata_json' => json_encode(['active_buffs' => []], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed> */
    private function loadRow(int $playerId): array
    {
        if (!$this->tableExists('player_vitals')) {
            $caps = $this->capsForPlayer($playerId);

            return [
                'energy_current' => $caps['energy'],
                'hunger_current' => $caps['hunger'],
                'thirst_current' => $caps['thirst'],
                'resting_until' => null,
                'last_resolved_at' => date('Y-m-d H:i:s'),
                'metadata_json' => null,
            ];
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM player_vitals WHERE player_id = :player_id LIMIT 1');
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @param array<string, mixed> $row */
    private function isResting(array $row): bool
    {
        $until = strtotime((string) ($row['resting_until'] ?? '')) ?: 0;

        return $until > time();
    }

    /** @return list<array<string, mixed>> */
    private function activeBuffsFromMetadata(mixed $raw): array
    {
        $metadata = $this->parseJson($raw);
        $now = time();
        $active = [];
        foreach ((array) ($metadata['active_buffs'] ?? []) as $buff) {
            if (!is_array($buff)) {
                continue;
            }
            $expires = strtotime((string) ($buff['expires_at'] ?? '')) ?: 0;
            if ($expires > 0 && $expires <= $now) {
                continue;
            }
            $active[] = $buff;
        }

        return $active;
    }

    /** @param list<array<string, mixed>> $buffs */
    private function energyCostReduction(array $buffs): float
    {
        $total = 0.0;
        foreach ($buffs as $buff) {
            $stats = (array) ($buff['stats'] ?? []);
            $total += (float) ($stats['energy_cost_reduction'] ?? 0);
        }

        return min(0.75, max(0.0, $total));
    }

    private function hasActiveExpedition(int $playerId): bool
    {
        if (!$this->tableExists('expedition_instances')) {
            return false;
        }

        $stmt = $this->pdo()->prepare("SELECT 1 FROM expedition_instances WHERE player_id = :player_id AND status = 'active' LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function loadPlayer(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return array<string, mixed> */
    private function parseJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

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

    private function infiniteEnergyEnabled(): bool
    {
        if (filter_var($_ENV['APP_INFINITE_ENERGY'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return (bool) Config::get('app.infinite_energy', false);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
