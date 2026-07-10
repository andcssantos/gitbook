<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\DTO\SplitStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\InventoryOrganizeService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\ItemRenameService;
use App\Game\Inventory\Services\StackMergeService;
use App\Game\Inventory\Services\StackSplitService;
use App\Game\Enhancement\DTO\ApplyJewelRequest;
use App\Game\Enhancement\Services\JewelEnhancementService;
use App\Game\Socketing\DTO\ApplyGemSocketRequest;
use App\Game\Socketing\Services\GemSocketService;
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

    public function enhancePreview(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'jewel_item_public_id' => 'required|string|max:64',
                'target_item_public_id' => 'required|string|max:64',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $preview = (new JewelEnhancementService())->preview(
                (int) $player['id'],
                (string) $payload['jewel_item_public_id'],
                (string) $payload['target_item_public_id']
            );

            $this->success($preview, 'Enhancement preview.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function enhance(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'jewel_item_public_id' => 'required|string|max:64',
                'target_item_public_id' => 'required|string|max:64',
                'expected_jewel_placement_version' => 'required|int|min:1',
                'expected_target_placement_version' => 'required|int|min:1',
                'confirm' => 'nullable|boolean',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new JewelEnhancementService())->apply(ApplyJewelRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Enhancement resolved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function socketPreview(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'gem_item_public_id' => 'required|string|max:64',
                'target_item_public_id' => 'required|string|max:64',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $preview = (new GemSocketService())->preview(
                (int) $player['id'],
                (string) $payload['gem_item_public_id'],
                (string) $payload['target_item_public_id']
            );

            $this->success($preview, 'Socket preview.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function socket(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'gem_item_public_id' => 'required|string|max:64',
                'target_item_public_id' => 'required|string|max:64',
                'expected_gem_placement_version' => 'required|int|min:1',
                'expected_target_placement_version' => 'required|int|min:1',
                'confirm' => 'nullable|boolean',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new GemSocketService())->apply(ApplyGemSocketRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Gem socketed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function organizeContainer(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $containerPublicId = (string) ($params['containerPublicId'] ?? '');
            $result = (new InventoryOrganizeService())->organize((int) $player['id'], $containerPublicId);

            $this->success($result, 'Inventory container organized.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function renameItem(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'item_name' => 'nullable|string|max:48',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $result = (new ItemRenameService())->rename(
                (int) $player['id'],
                $itemPublicId,
                array_key_exists('item_name', $payload) ? (string) $payload['item_name'] : null
            );

            $this->success($result, 'Inventory item renamed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
