<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\DTO\SplitStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\StackMergeService;
use App\Game\Inventory\Services\StackSplitService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $state = (new InventoryStateService())->forPlayer((int) $player['id']);

        $this->success($state, 'Inventory state.');
    }

    public function summary(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $summary = (new InventoryStateService())->summaryForPlayer((int) $player['id']);

        $this->success($summary, 'Inventory summary.');
    }

    public function showContainer(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $containerPublicId = (string) ($params['containerPublicId'] ?? '');
            $state = (new InventoryStateService())->containerForPlayer((int) $player['id'], $containerPublicId);

            $this->success($state, 'Inventory container.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function showItem(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $state = (new InventoryStateService())->itemForPlayer((int) $player['id'], $itemPublicId);

            $this->success($state, 'Inventory item.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function move(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'item_public_id' => 'required|string|max:64',
                'source_container_public_id' => 'required|string|max:64',
                'target_container_public_id' => 'required|string|max:64',
                'grid_x' => 'required|int|min:0',
                'grid_y' => 'required|int|min:0',
                'rotated' => 'nullable|boolean',
                'expected_placement_version' => 'required|int|min:1',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new InventoryMoveService())->move(MoveItemRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Inventory item moved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function mergeStack(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'source_item_public_id' => 'required|string|max:64',
                'target_item_public_id' => 'required|string|max:64',
                'quantity' => 'required|int|min:1',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new StackMergeService())->merge(MergeStackRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Inventory stacks merged.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function splitStack(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'source_item_public_id' => 'required|string|max:64',
                'source_container_public_id' => 'required|string|max:64',
                'target_container_public_id' => 'required|string|max:64',
                'quantity' => 'required|int|min:1',
                'grid_x' => 'required|int|min:0',
                'grid_y' => 'required|int|min:0',
                'expected_placement_version' => 'required|int|min:1',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new StackSplitService())->split(SplitStackRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Inventory stack split.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
