<?php

namespace App\Game\Exploration\Services;

use App\Support\DB;
use PDO;

class ExplorationTrapMitigationService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array<string, mixed> */
    public function forPlayer(int $playerId): array
    {
        $equipped = $this->equippedGloves($playerId);
        if ($equipped === null) {
            return [
                'active' => false,
                'trap_reduction' => 0.0,
                'item' => null,
            ];
        }

        $config = $this->parseJson($equipped['base_config'] ?? null);
        $trapReduction = max(0.0, min(0.5, (float) ($config['trap_reduction'] ?? 0)));

        return [
            'active' => $trapReduction > 0,
            'trap_reduction' => round($trapReduction, 3),
            'item' => [
                'public_id' => (string) ($equipped['item_public_id'] ?? ''),
                'definition_code' => (string) ($equipped['definition_code'] ?? ''),
                'name' => (string) ($equipped['item_name'] ?? $equipped['definition_name'] ?? ''),
            ],
        ];
    }

    public function trapReductionForPlayer(int $playerId): float
    {
        return (float) ($this->forPlayer($playerId)['trap_reduction'] ?? 0.0);
    }

    private function equippedGloves(int $playerId): ?array
    {
        if (!$this->tableExists('player_equipment')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT
                ii.public_id AS item_public_id,
                ii.item_name,
                id.code AS definition_code,
                id.name AS definition_name,
                id.base_config
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
                AND es.code = :slot_code
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'slot_code' => 'gloves',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
