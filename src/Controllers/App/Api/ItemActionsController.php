<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\ItemActionAvailabilityService;
use App\Game\Items\Services\ItemActionExecuteService;
use App\Game\Items\Services\ItemBulkActionService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class ItemActionsController extends Controller
{
    public function index(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $item = $this->loadOwnedItem((int) $player['id'], $itemPublicId);
            $actions = (new ItemActionAvailabilityService())->listForItem($item);

            $this->success([
                'item_public_id' => $itemPublicId,
                'actions' => $actions,
            ], 'Item actions.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function execute(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'confirm' => 'nullable|boolean',
                'price_premium' => 'nullable|int|min:1',
                'target_slot' => 'nullable|string|max:40',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $actionCode = (string) ($params['actionCode'] ?? '');
            $confirmed = filter_var($payload['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $result = (new ItemActionExecuteService())->execute(
                (int) $player['id'],
                $itemPublicId,
                $actionCode,
                $confirmed,
                $payload
            );

            $this->success($result, 'Item action executed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function bulk(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'action_code' => 'required|string|max:40',
                'item_public_ids' => 'required|array',
                'confirm' => 'nullable|boolean',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $confirmed = filter_var($payload['confirm'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $result = (new ItemBulkActionService())->execute(
                (int) $player['id'],
                (string) $payload['action_code'],
                (array) $payload['item_public_ids'],
                $confirmed
            );

            $this->audit('items.actions.bulk.summary', [
                'batch_id' => $result['batch_id'],
                'player_id' => (int) $player['id'],
                'action' => $result['action'],
                'requested' => $result['requested'],
                'succeeded' => $result['succeeded'],
                'failed' => $result['failed'],
            ]);

            $this->success($result, 'Bulk item action executed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function showBulk(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $batchId = (string) ($params['batchId'] ?? '');
            $result = (new ItemBulkActionService())->findForPlayer((int) $player['id'], $batchId);

            $this->success($result, 'Bulk item action details.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    private function loadOwnedItem(int $playerId, string $publicId): array
    {
        $items = new ItemInstanceRepository();
        $item = $items->findByPublicIdAndOwner($publicId, $playerId);
        if ($item !== null) {
            return $item;
        }

        if ($items->findByPublicId($publicId) !== null) {
            throw new InventoryException('ITEM_ACTION_FORBIDDEN', 'Item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
    }
}
