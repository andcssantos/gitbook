<?php

namespace App\Game\Socketing\Services;

use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;
use App\Support\DB;
use App\Utils\Config;
use PDO;
use Throwable;

class GemSocketRemoveService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function previewUnsocket(int $playerId, string $targetPublicId, int $socketIndex): array
    {
        $target = $this->target($playerId, $targetPublicId);
        $socket = (new ItemInstanceSocketRepository($this->pdo()))->findFilledByIndex((int) $target['id'], $socketIndex);
        if ($socket === null) {
            throw new InventoryException('SOCKET_NOT_FILLED', 'The selected socket does not contain a gem.', 422);
        }
        $gem = (new ItemInstanceRepository($this->pdo()))->findById((int) $socket['gem_item_instance_id']);
        $cost = (int) Config::get('socketing.unsocket.base_gold_cost', 150);

        return [
            'target_item_public_id' => (string) $target['public_id'],
            'socket_index' => $socketIndex,
            'gem_item_public_id' => $gem['public_id'] ?? null,
            'gem_definition_code' => $gem['definition_code'] ?? null,
            'cost' => ['currency' => 'gold', 'amount' => $cost],
            'confirmation_required' => true,
        ];
    }

    public function unsocket(int $playerId, string $targetPublicId, int $socketIndex, bool $confirm): array
    {
        if (!$confirm) {
            throw new InventoryException('SOCKET_CONFIRMATION_REQUIRED', 'Unsocketing requires confirmation.', 422);
        }

        $result = $this->transaction(function () use ($playerId, $targetPublicId, $socketIndex): array {
            $target = $this->target($playerId, $targetPublicId, true);
            $sockets = new ItemInstanceSocketRepository($this->pdo());
            $socket = $sockets->findFilledByIndex((int) $target['id'], $socketIndex, true);
            if ($socket === null) {
                throw new InventoryException('SOCKET_NOT_FILLED', 'The selected socket does not contain a gem.', 422);
            }

            $items = new ItemInstanceRepository($this->pdo());
            $gem = $items->findById((int) $socket['gem_item_instance_id'], true);
            if ($gem === null || (int) $gem['owner_player_id'] !== $playerId) {
                throw new InventoryException('SOCKET_GEM_NOT_FOUND', 'The socketed gem is no longer available.', 409);
            }

            $cost = (int) Config::get('socketing.unsocket.base_gold_cost', 150);
            $balance = (new PlayerCurrencyService($this->pdo()))->debit(
                $playerId, 'gold', $cost, 'SOCKET_UNSOCKET', 'item_instance', (string) $target['public_id'],
                ['socket_index' => $socketIndex, 'gem_item_public_id' => (string) $gem['public_id']]
            );
            $property = $this->pdo()->prepare('DELETE FROM item_instance_properties WHERE item_instance_id = :item_instance_id AND source = :source');
            $property->execute(['item_instance_id' => (int) $target['id'], 'source' => 'socketed_gem_' . $socketIndex]);
            $sockets->clearSocketedGem((int) $socket['id']);
            $sockets->markEmpty((int) $socket['id']);
            $items->updateState((int) $gem['id'], 'available');
            $returnedGem = $items->findById((int) $gem['id'], true);
            $placement = (new InventoryAutoPlacementService($this->pdo()))->autoPlaceExistingItem($playerId, $returnedGem ?? $gem);

            return [
                'target_item_public_id' => (string) $target['public_id'],
                'socket_index' => $socketIndex,
                'gem_item_public_id' => (string) $gem['public_id'],
                'cost' => ['currency' => 'gold', 'amount' => $cost, 'balance_after' => $balance],
                'placement' => $placement,
            ];
        });
        InventoryStateService::forgetCombatSnapshot($playerId);
        return $result;
    }

    private function target(int $playerId, string $publicId, bool $lock = false): array
    {
        $target = (new ItemInstanceRepository($this->pdo()))->findByPublicIdAndOwner($publicId, $playerId, $lock);
        if ($target !== null) return $target;
        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
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
