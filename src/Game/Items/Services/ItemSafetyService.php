<?php

namespace App\Game\Items\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;

class ItemSafetyService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function flagsForItem(int $playerId, int $itemInstanceId): array
    {
        if (!$this->tableExists('player_item_flags')) {
            return $this->emptyFlags();
        }

        $stmt = $this->pdo()->prepare('SELECT locked, favorite, wishlist, locked_at, favorited_at, wishlisted_at
            FROM player_item_flags
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->emptyFlags();
        }

        return [
            'locked' => (bool) $row['locked'],
            'favorite' => (bool) $row['favorite'],
            'wishlist' => (bool) $row['wishlist'],
            'locked_at' => $row['locked_at'] !== null ? (string) $row['locked_at'] : null,
            'favorited_at' => $row['favorited_at'] !== null ? (string) $row['favorited_at'] : null,
            'wishlisted_at' => $row['wishlisted_at'] !== null ? (string) $row['wishlisted_at'] : null,
        ];
    }

    public function isLocked(int $playerId, int $itemInstanceId): bool
    {
        return (bool) $this->flagsForItem($playerId, $itemInstanceId)['locked'];
    }

    public function assertNotLocked(int $playerId, int $itemInstanceId, string $actionCode): void
    {
        if ($this->isLocked($playerId, $itemInstanceId)) {
            throw new InventoryException('ITEM_LOCKED', "Item travado. Destrave antes de executar {$actionCode}.", 423);
        }
    }

    public function setLocked(int $playerId, array $item, bool $locked): array
    {
        $itemInstanceId = (int) ($item['id'] ?? $item['item_instance_id'] ?? 0);
        if ($itemInstanceId <= 0) {
            throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $this->ensureFlagRow($playerId, $itemInstanceId);
        $stmt = $this->pdo()->prepare('UPDATE player_item_flags
            SET locked = :locked,
                locked_at = CASE WHEN :locked_at_flag = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id');
        $stmt->execute([
            'locked' => $locked ? 1 : 0,
            'locked_at_flag' => $locked ? 1 : 0,
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);

        $this->record($item, $playerId, $locked ? 'locked' : 'unlocked');

        return [
            'action' => $locked ? 'LOCK_ITEM' : 'UNLOCK_ITEM',
            'item_public_id' => (string) ($item['public_id'] ?? $item['item_public_id'] ?? ''),
            'flags' => $this->flagsForItem($playerId, $itemInstanceId),
        ];
    }

    public function setFavorite(int $playerId, array $item, bool $favorite): array
    {
        $itemInstanceId = (int) ($item['id'] ?? $item['item_instance_id'] ?? 0);
        if ($itemInstanceId <= 0) {
            throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $this->ensureFlagRow($playerId, $itemInstanceId);
        $stmt = $this->pdo()->prepare('UPDATE player_item_flags
            SET favorite = :favorite,
                favorited_at = CASE WHEN :favorite_at_flag = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id');
        $stmt->execute([
            'favorite' => $favorite ? 1 : 0,
            'favorite_at_flag' => $favorite ? 1 : 0,
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);

        $this->record($item, $playerId, $favorite ? 'favorited' : 'unfavorited');

        return [
            'action' => $favorite ? 'FAVORITE_ITEM' : 'UNFAVORITE_ITEM',
            'item_public_id' => (string) ($item['public_id'] ?? $item['item_public_id'] ?? ''),
            'flags' => $this->flagsForItem($playerId, $itemInstanceId),
        ];
    }

    public function setWishlist(int $playerId, array $item, bool $wishlist): array
    {
        $itemInstanceId = (int) ($item['id'] ?? $item['item_instance_id'] ?? 0);
        if ($itemInstanceId <= 0) {
            throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $this->ensureFlagRow($playerId, $itemInstanceId);
        $stmt = $this->pdo()->prepare('UPDATE player_item_flags
            SET wishlist = :wishlist,
                wishlisted_at = CASE WHEN :wishlist_at_flag = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id');
        $stmt->execute([
            'wishlist' => $wishlist ? 1 : 0,
            'wishlist_at_flag' => $wishlist ? 1 : 0,
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);

        $this->record($item, $playerId, $wishlist ? 'wishlisted' : 'unwishlisted');

        return [
            'action' => $wishlist ? 'WISHLIST_ITEM' : 'UNWISHLIST_ITEM',
            'item_public_id' => (string) ($item['public_id'] ?? $item['item_public_id'] ?? ''),
            'flags' => $this->flagsForItem($playerId, $itemInstanceId),
        ];
    }

    public function record(array $item, ?int $playerId, string $eventType, ?array $metadata = null, ?string $idempotencyKey = null): void
    {
        if (!$this->tableExists('item_history_events')) {
            return;
        }

        $itemInstanceId = (int) ($item['id'] ?? $item['item_instance_id'] ?? 0);
        $itemPublicId = (string) ($item['public_id'] ?? $item['item_public_id'] ?? '');
        if ($itemPublicId === '') {
            return;
        }

        $stmt = $this->pdo()->prepare('INSERT INTO item_history_events (item_instance_id, item_public_id, player_id, event_type, metadata_json, idempotency_key)
            VALUES (:item_instance_id, :item_public_id, :player_id, :event_type, :metadata_json, :idempotency_key)');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId > 0 ? $itemInstanceId : null,
            'item_public_id' => $itemPublicId,
            'player_id' => $playerId,
            'event_type' => $eventType,
            'metadata_json' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function historyForItem(int $itemInstanceId, string $itemPublicId, int $limit = 12): array
    {
        if (!$this->tableExists('item_history_events')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT event_type, metadata_json, created_at
            FROM item_history_events
            WHERE item_instance_id = :item_instance_id OR item_public_id = :item_public_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit');
        $stmt->bindValue('item_instance_id', $itemInstanceId, PDO::PARAM_INT);
        $stmt->bindValue('item_public_id', $itemPublicId);
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $metadata = null;
            if ($row['metadata_json'] !== null && $row['metadata_json'] !== '') {
                $decoded = json_decode((string) $row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : null;
            }

            return [
                'type' => (string) $row['event_type'],
                'label' => self::labelForEvent((string) $row['event_type']),
                'metadata' => $metadata,
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ensureFlagRow(int $playerId, int $itemInstanceId): void
    {
        if (!$this->tableExists('player_item_flags')) {
            throw new InventoryException('ITEM_FLAGS_NOT_AVAILABLE', 'Item safety flags are not available.', 500);
        }

        $stmt = $this->pdo()->prepare('INSERT INTO player_item_flags (player_id, item_instance_id, updated_at)
            VALUES (:player_id, :item_instance_id, CURRENT_TIMESTAMP)');
        try {
            $stmt->execute([
                'player_id' => $playerId,
                'item_instance_id' => $itemInstanceId,
            ]);
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000'], true) || in_array($driverCode, ['1062', '19'], true);
    }

    private function emptyFlags(): array
    {
        return [
            'locked' => false,
            'favorite' => false,
            'wishlist' => false,
            'locked_at' => null,
            'favorited_at' => null,
            'wishlisted_at' => null,
        ];
    }

    private static function labelForEvent(string $eventType): string
    {
        return match ($eventType) {
            'locked' => 'Item travado',
            'unlocked' => 'Item destravado',
            'favorited' => 'Item favoritado',
            'unfavorited' => 'Favorito removido',
            'wishlisted' => 'Adicionado a wishlist',
            'unwishlisted' => 'Removido da wishlist',
            'bulk_action_applied' => 'Acao em lote aplicada',
            'bulk_action_rejected' => 'Acao em lote recusada',
            'discarded' => 'Item descartado',
            'sold_npc' => 'Vendido para NPC',
            'listed_market' => 'Listado no mercado',
            'dismantled' => 'Item desmanchado',
            'crafted_consumed' => 'Consumido em craft',
            'crafted_created' => 'Criado por craft',
            default => ucfirst(str_replace('_', ' ', $eventType)),
        };
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
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
