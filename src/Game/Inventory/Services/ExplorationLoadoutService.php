<?php

namespace App\Game\Inventory\Services;

use App\Game\Equipment\Services\EquipmentService;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;
use Throwable;

class ExplorationLoadoutService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function get(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM player_exploration_loadouts WHERE player_id = :player_id LIMIT 1');
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->present(is_array($row) ? $row : []);
    }

    public function save(int $playerId, ?string $backpackItemPublicId, array $toolItemPublicIds, array $potionItemPublicIds, ?string $notes): array
    {
        $tools = $this->normalizeIds($toolItemPublicIds);
        $potions = $this->normalizeIds($potionItemPublicIds);
        $backpack = trim((string) $backpackItemPublicId) ?: null;
        $this->validateOwned($playerId, array_filter(array_merge([$backpack], $tools, $potions)));

        return $this->transaction(function () use ($playerId, $backpack, $tools, $potions, $notes): array {
            $payload = [
                'player_id' => $playerId,
                'backpack_item_public_id' => $backpack,
                'tool_item_public_ids_json' => json_encode($tools, JSON_THROW_ON_ERROR),
                'potion_item_public_ids_json' => json_encode($potions, JSON_THROW_ON_ERROR),
                'notes' => ($notes = trim((string) $notes)) === '' ? null : mb_substr($notes, 0, 180),
            ];
            if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $sql = 'INSERT INTO player_exploration_loadouts (player_id, backpack_item_public_id, tool_item_public_ids_json, potion_item_public_ids_json, notes, updated_at)
                    VALUES (:player_id, :backpack_item_public_id, :tool_item_public_ids_json, :potion_item_public_ids_json, :notes, CURRENT_TIMESTAMP)
                    ON CONFLICT(player_id) DO UPDATE SET backpack_item_public_id = excluded.backpack_item_public_id,
                    tool_item_public_ids_json = excluded.tool_item_public_ids_json, potion_item_public_ids_json = excluded.potion_item_public_ids_json,
                    notes = excluded.notes, updated_at = CURRENT_TIMESTAMP';
            } else {
                $sql = 'INSERT INTO player_exploration_loadouts (player_id, backpack_item_public_id, tool_item_public_ids_json, potion_item_public_ids_json, notes)
                    VALUES (:player_id, :backpack_item_public_id, :tool_item_public_ids_json, :potion_item_public_ids_json, :notes)
                    ON DUPLICATE KEY UPDATE backpack_item_public_id = VALUES(backpack_item_public_id),
                    tool_item_public_ids_json = VALUES(tool_item_public_ids_json), potion_item_public_ids_json = VALUES(potion_item_public_ids_json),
                    notes = VALUES(notes), updated_at = CURRENT_TIMESTAMP';
            }
            $this->pdo()->prepare($sql)->execute($payload);
            return $this->get($playerId);
        });
    }

    public function apply(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $loadout = $this->get($playerId);
            $this->validateOwned($playerId, $loadout['tool_item_public_ids']);
            $equipment = new EquipmentService($this->pdo());
            $applied = [];
            $skipped = [];
            if ($loadout['backpack_item_public_id'] !== null) {
                try {
                    $applied[] = $equipment->equip($playerId, $loadout['backpack_item_public_id'], 'backpack');
                } catch (InventoryException $e) {
                    $skipped[] = ['item_public_id' => $loadout['backpack_item_public_id'], 'reason' => $e->errorCode()];
                }
            }
            foreach ($loadout['potion_item_public_ids'] as $publicId) {
                try {
                    $applied[] = $equipment->equip($playerId, $publicId);
                } catch (InventoryException $e) {
                    $skipped[] = ['item_public_id' => $publicId, 'reason' => $e->errorCode()];
                }
            }
            return ['loadout' => $loadout, 'applied' => $applied, 'skipped' => $skipped];
        });
    }

    private function validateOwned(int $playerId, array $publicIds): void
    {
        $items = new ItemInstanceRepository($this->pdo());
        foreach ($publicIds as $publicId) {
            if ($items->findByPublicIdAndOwner((string) $publicId, $playerId) === null) {
                throw new InventoryException('EXPLORATION_LOADOUT_ITEM_NOT_OWNED', 'An exploration loadout item is not owned by the player.', 422);
            }
        }
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): string => trim((string) $id), $ids))));
        if (count($ids) > 8 || array_filter($ids, static fn (string $id): bool => strlen($id) > 64)) {
            throw new InventoryException('EXPLORATION_LOADOUT_INVALID_ITEMS', 'Exploration loadout items are invalid.', 422);
        }
        return $ids;
    }

    private function present(array $row): array
    {
        $decode = static function (mixed $value): array {
            $decoded = is_string($value) ? json_decode($value, true) : [];
            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        };
        return [
            'backpack_item_public_id' => !empty($row['backpack_item_public_id']) ? (string) $row['backpack_item_public_id'] : null,
            'tool_item_public_ids' => $decode($row['tool_item_public_ids_json'] ?? null),
            'potion_item_public_ids' => $decode($row['potion_item_public_ids_json'] ?? null),
            'notes' => !empty($row['notes']) ? (string) $row['notes'] : null,
        ];
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $result = $callback();
            if ($started) $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function pdo(): PDO { return $this->pdo ?? DB::pdo(); }
}
