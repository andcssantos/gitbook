<?php

namespace App\Game\Campaign\Services;

use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;

/**
 * Poções do cinto (potion_1..4) durante a fase idle da campanha.
 */
class CampaignPotionService
{
    public const AUTO_HEAL_RATIO = 0.38;

    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemInstanceRepository $items = null
    ) {
        $this->pdo ??= DB::pdo();
        $this->items ??= new ItemInstanceRepository($this->pdo);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function beltForPlayer(int $playerId): array
    {
        if (!$this->tableExists('player_equipment') || !$this->tableExists('equipment_slots')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT
                es.code AS slot_code,
                ii.id AS item_instance_id,
                ii.public_id,
                ii.quantity,
                id.code AS definition_code,
                id.name,
                id.base_config
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
              AND es.code LIKE 'potion_%'
            ORDER BY es.sort_order ASC, es.code ASC");
        $stmt->execute(['player_id' => $playerId]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $effect = $this->resolveEffect($row);
            $out[] = [
                'slot_code' => (string) ($row['slot_code'] ?? ''),
                'public_id' => (string) ($row['public_id'] ?? ''),
                'definition_code' => (string) ($row['definition_code'] ?? ''),
                'name' => (string) ($row['name'] ?? 'Pocao'),
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'heal_amount' => (int) ($effect['heal_amount'] ?? 0),
                'heal_pct' => (float) ($effect['heal_pct'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array{hp:int,events:list<array<string,mixed>>,potions:list<array<string,mixed>>,used:bool}
     */
    public function useSlot(int $playerId, int $currentHp, int $maxHp, ?string $slotCode = null): array
    {
        $potion = $this->findBeltPotion($playerId, $slotCode);
        if ($potion === null) {
            throw new \RuntimeException('Nenhuma pocao equipada nesse slot.');
        }

        return $this->applyPotion($playerId, $potion, $currentHp, $maxHp, false);
    }

    /**
     * @return array{hp:int,events:list<array<string,mixed>>,potions:list<array<string,mixed>>,used:bool}
     */
    public function autoHealIfNeeded(int $playerId, int $currentHp, int $maxHp): array
    {
        $maxHp = max(1, $maxHp);
        if ($currentHp <= 0 || ($currentHp / $maxHp) > self::AUTO_HEAL_RATIO) {
            return [
                'hp' => $currentHp,
                'events' => [],
                'potions' => $this->beltForPlayer($playerId),
                'used' => false,
            ];
        }

        $potion = $this->findBeltPotion($playerId, null);
        if ($potion === null) {
            return [
                'hp' => $currentHp,
                'events' => [],
                'potions' => [],
                'used' => false,
            ];
        }

        return $this->applyPotion($playerId, $potion, $currentHp, $maxHp, true);
    }

    /**
     * @param array<string, mixed> $potion
     * @return array{hp:int,events:list<array<string,mixed>>,potions:list<array<string,mixed>>,used:bool}
     */
    private function applyPotion(int $playerId, array $potion, int $currentHp, int $maxHp, bool $auto): array
    {
        $maxHp = max(1, $maxHp);
        $effect = $this->resolveEffect($potion);
        $heal = (int) ($effect['heal_amount'] ?? 0);
        if ($heal <= 0 && (float) ($effect['heal_pct'] ?? 0) > 0) {
            $heal = (int) round($maxHp * (float) $effect['heal_pct']);
        }
        if ($heal <= 0) {
            // Fallback de combate idle: qualquer poção do cinto recupera vida na fase.
            $heal = (int) max(20, round($maxHp * 0.30));
        }

        $before = $currentHp;
        $after = min($maxHp, $currentHp + $heal);
        $actual = max(0, $after - $before);
        $remaining = $this->consumeOne($playerId, $potion);

        $events = [[
            'type' => 'potion_heal',
            'message' => ($auto ? 'Auto ' : '') . '+' . $actual . ' HP (' . ($potion['name'] ?? 'pocao') . ')',
            'damage' => $actual,
            'target' => 'player',
            'auto' => $auto,
        ]];

        return [
            'hp' => $after,
            'events' => $events,
            'potions' => $this->beltForPlayer($playerId),
            'used' => true,
            'remaining_quantity' => $remaining,
        ];
    }

    /** @return array<string, mixed>|null */
    private function findBeltPotion(int $playerId, ?string $slotCode): ?array
    {
        foreach ($this->beltRows($playerId) as $row) {
            if ($slotCode !== null && $slotCode !== '' && (string) ($row['slot_code'] ?? '') !== $slotCode) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beltRows(int $playerId): array
    {
        if (!$this->tableExists('player_equipment') || !$this->tableExists('equipment_slots')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT
                es.code AS slot_code,
                ii.id AS item_instance_id,
                ii.public_id,
                ii.quantity,
                id.code AS definition_code,
                id.name,
                id.base_config
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
              AND es.code LIKE 'potion_%'
            ORDER BY es.sort_order ASC, es.code ASC");
        $stmt->execute(['player_id' => $playerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{heal_amount:int,heal_pct:float}
     */
    private function resolveEffect(array $row): array
    {
        $config = $this->parseJson($row['base_config'] ?? null);
        $effect = is_array($config['use_effect'] ?? null) ? $config['use_effect'] : [];

        return [
            'heal_amount' => max(0, (int) ($effect['heal_amount'] ?? $effect['heal'] ?? 0)),
            'heal_pct' => max(0.0, (float) ($effect['heal_pct'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $potion */
    private function consumeOne(int $playerId, array $potion): int
    {
        $itemId = (int) ($potion['item_instance_id'] ?? 0);
        $publicId = (string) ($potion['public_id'] ?? '');
        $locked = $this->items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($locked === null) {
            throw new \RuntimeException('Pocao nao encontrada.');
        }

        $qty = max(1, (int) ($locked['quantity'] ?? 1));
        if ($qty > 1) {
            $this->items->updateQuantity($itemId, $qty - 1);

            return $qty - 1;
        }

        $this->pdo()->prepare('DELETE FROM player_equipment WHERE player_id = :player_id AND item_instance_id = :item_instance_id')
            ->execute([
                'player_id' => $playerId,
                'item_instance_id' => $itemId,
            ]);
        $this->items->deleteById($itemId);

        return 0;
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
        return $this->pdo ??= DB::pdo();
    }
}
