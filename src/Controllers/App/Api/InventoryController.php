<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\DTO\SplitStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\ContainerRenameService;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\InventoryOrganizeService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\ItemRenameService;
use App\Game\Inventory\Services\MainInventoryExpansionService;
use App\Game\Inventory\Services\StackMergeService;
use App\Game\Inventory\Services\StackSplitService;
use App\Game\Inventory\Services\StashVaultService;
use App\Game\Inventory\Services\ExplorationLoadoutService;
use App\Game\Inventory\Services\ItemSetCodexService;
use App\Game\Equipment\Services\EquipmentLoadoutService;
use App\Game\Crafting\Services\CraftRecipeJournalService;
use App\Game\Crafting\Services\CraftingWorkspaceService;
use App\Game\Enhancement\DTO\ApplyJewelRequest;
use App\Game\Enhancement\Services\JewelEnhancementService;
use App\Game\Socketing\DTO\ApplyGemSocketRequest;
use App\Game\Socketing\Services\GemSocketService;
use App\Game\Socketing\Services\GemSocketRemoveService;
use App\Game\Items\Services\ItemInvestigationService;
use App\Game\Materials\Services\PlayerMaterialStashService;
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
            $payload = Request::body();
            $mode = (string) ($payload['mode'] ?? 'compact');
            $result = (new InventoryOrganizeService())->organize((int) $player['id'], $containerPublicId, $mode);

            $this->success($result, 'Inventory container organized.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function renameContainer(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'name' => 'nullable|string|max:48',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $containerPublicId = (string) ($params['containerPublicId'] ?? '');
            $result = (new ContainerRenameService())->rename(
                (int) $player['id'],
                $containerPublicId,
                array_key_exists('name', $payload) ? (string) $payload['name'] : null
            );

            $this->success($result, 'Inventory container renamed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
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

    public function investigateItem(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $report = (new ItemInvestigationService())->investigate((int) $player['id'], $itemPublicId);

            $this->success($report, 'Item investigation report.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function expandContainerPreview(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $containerPublicId = (string) ($params['containerPublicId'] ?? '');
            $preview = (new MainInventoryExpansionService())->preview((int) $player['id'], $containerPublicId);

            $this->success($preview, 'Inventory expansion preview.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function expandContainer(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $containerPublicId = (string) ($params['containerPublicId'] ?? '');
            $result = (new MainInventoryExpansionService())->expand((int) $player['id'], $containerPublicId);

            $this->success($result, 'Inventario expandido.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function materials(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $tab = Request::query()['tab'] ?? null;
            $stash = (new PlayerMaterialStashService())->listForPlayer((int) $player['id'], $tab !== null ? (string) $tab : null);

            $this->success($stash, 'Player material stash.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function craftingWorkspaces(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $service = new CraftingWorkspaceService();

        $this->success([
            'workspaces' => $service->workspaces(),
            'slot_count' => 6,
        ], 'Crafting workspaces.');
    }

    public function craftingPreview(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'workspace' => 'required|string|max:30',
                'slots' => 'required|array',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $preview = (new CraftingWorkspaceService())->preview(
                (int) $player['id'],
                (string) $payload['workspace'],
                (array) $payload['slots']
            );

            $this->success($preview, 'Crafting preview.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function craftingExecute(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'workspace' => 'required|string|max:30',
                'slots' => 'required|array',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new CraftingWorkspaceService())->execute(
                (int) $player['id'],
                (string) $payload['workspace'],
                (array) $payload['slots']
            );

            $this->success($result, 'Crafting completed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function craftingShareRecipe(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'recipe_code' => 'required|string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            (new CraftingWorkspaceService())->shareRecipe((int) $player['id'], (string) $payload['recipe_code']);

            $this->success(['recipe_code' => (string) $payload['recipe_code'], 'visibility' => 'shared'], 'Receita compartilhada com todos os jogadores.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function setCodex(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $this->success((new ItemSetCodexService())->forPlayer((int) $player['id']), 'Item set codex.');
    }

    public function toggleSetWishlist(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['definition_code' => 'required|string|max:80', 'wishlisted' => 'required|boolean']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new ItemSetCodexService())->toggleDefinitionWishlist((int) $player['id'], (string) $payload['definition_code'], (bool) $payload['wishlisted']), 'Set wishlist updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        }
    }

    public function loadouts(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $this->success((new EquipmentLoadoutService())->listForPlayer((int) $player['id']), 'Equipment loadouts.');
    }

    public function saveLoadout(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['slot_index' => 'required|int|min:0|max:4', 'name' => 'required|string|max:48']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new EquipmentLoadoutService())->saveFromCurrent((int) $player['id'], (int) $payload['slot_index'], (string) $payload['name']), 'Equipment loadout saved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function applyLoadout(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['loadout_public_id' => 'required|string|max:64']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new EquipmentLoadoutService())->apply((int) $player['id'], (string) $payload['loadout_public_id']), 'Equipment loadout applied.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function unsocketPreview(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['target_item_public_id' => 'required|string|max:64', 'socket_index' => 'required|int|min:0']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new GemSocketRemoveService())->previewUnsocket((int) $player['id'], (string) $payload['target_item_public_id'], (int) $payload['socket_index']), 'Unsocket preview.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function unsocket(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['target_item_public_id' => 'required|string|max:64', 'socket_index' => 'required|int|min:0', 'confirm' => 'required|boolean']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new GemSocketRemoveService())->unsocket((int) $player['id'], (string) $payload['target_item_public_id'], (int) $payload['socket_index'], (bool) $payload['confirm']), 'Gem unsocketed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function craftingRecipes(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $this->success((new CraftRecipeJournalService())->listKnownForPlayer((int) $player['id']), 'Known crafting recipes.');
    }

    public function stashVault(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $this->success((new StashVaultService())->listForPlayer((int) $player['id'], Request::query()['tab'] ?? null), 'Stash vault.');
    }

    public function explorationLoadout(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();
        $this->success((new ExplorationLoadoutService())->get((int) $player['id']), 'Exploration loadout.');
    }

    public function saveExplorationLoadout(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), ['backpack_item_public_id' => 'nullable|string|max:64', 'tool_item_public_ids' => 'nullable|array', 'potion_item_public_ids' => 'nullable|array', 'notes' => 'nullable|string|max:180']);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new ExplorationLoadoutService())->save((int) $player['id'], $payload['backpack_item_public_id'] ?? null, (array) ($payload['tool_item_public_ids'] ?? []), (array) ($payload['potion_item_public_ids'] ?? []), $payload['notes'] ?? null), 'Exploration loadout saved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function applyExplorationLoadout(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new ExplorationLoadoutService())->apply((int) $player['id']), 'Exploration loadout applied.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
