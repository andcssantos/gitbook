<?php

namespace App\Game\Expeditions\Services;

use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;

class ExpeditionPotionUseService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionStateService $state = null,
        private ?ExpeditionRunModifiersService $runModifiers = null,
        private ?ItemInstanceRepository $items = null
    ) {
        $this->state ??= new ExpeditionStateService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
        $this->items ??= new ItemInstanceRepository($this->pdo);
    }

    /**
     * Consome 1 unidade de poção do cinto e aplica buff em metadata.active_buffs.
     *
     * @return array<string, mixed>
     */
    public function useFromBelt(int $playerId, ?string $slotCode = null, ?string $itemPublicId = null): array
    {
        $expedition = $this->state->activeForPlayer($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No active expedition found.');
        }

        $beltItem = $this->resolveBeltPotion($playerId, $slotCode, $itemPublicId);
        if ($beltItem === null) {
            throw new \RuntimeException('No potion found in the selected belt slot.');
        }

        $effect = $this->resolveUseEffect($beltItem);
        if ($effect === null) {
            throw new \RuntimeException('This potion has no usable expedition effect.');
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $activeBuffs = array_values(array_filter(
            (array) ($metadata['active_buffs'] ?? []),
            static fn (mixed $buff): bool => is_array($buff)
        ));

        $buffCode = (string) ($effect['buff_code'] ?? ($beltItem['definition_code'] . '_buff'));
        $durationSeconds = max(30, (int) ($effect['duration_seconds'] ?? 300));
        $now = time();
        $expiresAt = date('Y-m-d H:i:s', $now + $durationSeconds);

        // Substitui buff do mesmo code (refresh).
        $activeBuffs = array_values(array_filter(
            $activeBuffs,
            static fn (array $buff): bool => (string) ($buff['code'] ?? '') !== $buffCode
        ));

        $activeBuffs[] = [
            'code' => $buffCode,
            'label' => (string) ($effect['name'] ?? $beltItem['name']),
            'source_item_public_id' => (string) $beltItem['public_id'],
            'source_slot_code' => (string) $beltItem['slot_code'],
            'source_definition_code' => (string) $beltItem['definition_code'],
            'consumed_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => $expiresAt,
            'stats' => (array) ($effect['stats'] ?? []),
        ];

        $metadata['active_buffs'] = $activeBuffs;
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $metadata['run_modifiers'] = $this->runModifiers->summaryForMetadata($playerId, $biomeCode, $metadata);

        $remaining = $this->consumeOne($playerId, $beltItem);

        $this->pdo()->prepare('UPDATE expedition_instances SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                'id' => (int) $expedition['id'],
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

        return [
            'used' => true,
            'slot_code' => (string) $beltItem['slot_code'],
            'item_public_id' => (string) $beltItem['public_id'],
            'definition_code' => (string) $beltItem['definition_code'],
            'remaining_quantity' => $remaining,
            'buff' => $activeBuffs[array_key_last($activeBuffs)],
            'active_buffs' => $activeBuffs,
            'run_modifiers' => $metadata['run_modifiers'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBeltPotion(int $playerId, ?string $slotCode, ?string $itemPublicId): ?array
    {
        if (!$this->tableExists('player_equipment') || !$this->tableExists('equipment_slots')) {
            return null;
        }

        $slotCode = $slotCode !== null ? strtolower(trim($slotCode)) : null;
        $itemPublicId = $itemPublicId !== null ? trim($itemPublicId) : null;

        $sql = "SELECT
                es.code AS slot_code,
                ii.id AS item_instance_id,
                ii.public_id,
                ii.quantity,
                id.code AS definition_code,
                id.name,
                id.base_config,
                id.equip_slot_code,
                id.stackable
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
              AND es.code LIKE 'potion_%'";
        $params = ['player_id' => $playerId];

        if ($slotCode !== null && $slotCode !== '') {
            $sql .= ' AND es.code = :slot_code';
            $params['slot_code'] = $slotCode;
        }
        if ($itemPublicId !== null && $itemPublicId !== '') {
            $sql .= ' AND ii.public_id = :item_public_id';
            $params['item_public_id'] = $itemPublicId;
        }

        $sql .= ' ORDER BY es.sort_order ASC, es.code ASC LIMIT 1';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $beltItem
     * @return array{buff_code?: string, name?: string, duration_seconds?: int, stats: array<string, float|int>}|null
     */
    private function resolveUseEffect(array $beltItem): ?array
    {
        $baseConfig = $this->parseJson($beltItem['base_config'] ?? null);
        $effect = is_array($baseConfig['use_effect'] ?? null) ? $baseConfig['use_effect'] : null;
        if ($effect === null) {
            return null;
        }

        $stats = [];
        foreach ((array) ($effect['stats'] ?? []) as $code => $value) {
            if (is_array($value)) {
                $statCode = (string) ($value['code'] ?? '');
                $statValue = (float) ($value['value'] ?? 0);
            } else {
                $statCode = (string) $code;
                $statValue = (float) $value;
            }
            if ($statCode === '' || $statValue == 0.0) {
                continue;
            }
            $stats[$statCode] = $statValue;
        }

        if ($stats === []) {
            return null;
        }

        return [
            'buff_code' => (string) ($effect['buff_code'] ?? ($beltItem['definition_code'] . '_buff')),
            'name' => (string) ($effect['name'] ?? $beltItem['name']),
            'duration_seconds' => max(30, (int) ($effect['duration_seconds'] ?? 300)),
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string, mixed> $beltItem
     */
    private function consumeOne(int $playerId, array $beltItem): int
    {
        $itemId = (int) ($beltItem['item_instance_id'] ?? 0);
        $publicId = (string) ($beltItem['public_id'] ?? '');
        $locked = $this->items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($locked === null) {
            throw new \RuntimeException('Potion item was not found.');
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
        return $this->pdo ?? DB::pdo();
    }
}
