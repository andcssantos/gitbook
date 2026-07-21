<?php

namespace App\Game\Player\Services;

use App\Support\DB;
use PDO;

/**
 * Buffs temporarios da run (poções consumidas / efeitos em metadata).
 * Poções apenas equipadas no cinto já entram via character_stats do inventário;
 * este serviço cobre buffs explícitos em metadata.active_buffs (API futura de "usar poção").
 */
class PlayerTemporaryBuffService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array<string, mixed>|null $expeditionMetadata
     * @return array{
     *   active_buffs: list<array<string, mixed>>,
     *   stat_bonuses: array<string, float>,
     *   potion_belt: list<array<string, mixed>>
     * }
     */
    public function activeForPlayer(int $playerId, ?array $expeditionMetadata = null): array
    {
        $activeBuffs = [];
        $statBonuses = [];

        foreach ((array) ($expeditionMetadata['active_buffs'] ?? []) as $buff) {
            if (!is_array($buff)) {
                continue;
            }
            $code = (string) ($buff['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $expiresAt = (string) ($buff['expires_at'] ?? '');
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
                continue;
            }

            $activeBuffs[] = $buff;
            foreach ((array) ($buff['stats'] ?? []) as $statCode => $value) {
                $statKey = is_string($statCode) ? $statCode : (string) ($value['code'] ?? '');
                $statValue = is_array($value) ? (float) ($value['value'] ?? 0) : (float) $value;
                if ($statKey === '' || $statValue == 0.0) {
                    continue;
                }
                $statBonuses[$statKey] = ($statBonuses[$statKey] ?? 0.0) + $statValue;
            }
        }

        return [
            'active_buffs' => $activeBuffs,
            'stat_bonuses' => $statBonuses,
            'potion_belt' => $this->potionBeltSources($playerId),
        ];
    }

    /**
     * Lista poções no cinto e properties (informativo / UI; stats já estão no snapshot se equipadas).
     *
     * @return list<array<string, mixed>>
     */
    public function potionBeltSources(int $playerId): array
    {
        if (!$this->tableExists('player_equipment') || !$this->tableExists('equipment_slots')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT
                es.code AS slot_code,
                ii.public_id AS item_public_id,
                id.name AS item_name,
                id.code AS definition_code
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
              AND es.code LIKE 'potion_%'
            ORDER BY es.sort_order ASC, es.code ASC");
        $stmt->execute(['player_id' => $playerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $sources = [];
        foreach ($rows as $row) {
            $itemId = $this->itemInstanceIdByPublicId((string) $row['item_public_id']);
            $sources[] = [
                'slot_code' => (string) $row['slot_code'],
                'item_public_id' => (string) $row['item_public_id'],
                'name' => (string) $row['item_name'],
                'definition_code' => (string) $row['definition_code'],
                'stats' => $itemId > 0 ? $this->itemPropertyStats($itemId) : [],
            ];
        }

        return $sources;
    }

    /** @return array<string, float> */
    private function itemPropertyStats(int $itemInstanceId): array
    {
        if (!$this->tableExists('item_instance_properties') || !$this->tableExists('item_property_definitions')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                ipd.code,
                COALESCE(iip.integer_value, iip.numeric_value, 0) AS value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        $stats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $stats[$code] = (float) ($row['value'] ?? 0);
        }

        return $stats;
    }

    private function itemInstanceIdByPublicId(string $publicId): int
    {
        if ($publicId === '' || !$this->tableExists('item_instances')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
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
