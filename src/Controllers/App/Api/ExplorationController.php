<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Exploration\ExplorationException;
use App\Game\Exploration\Services\ExplorationActionExecuteService;
use App\Game\Exploration\Services\ExplorationAnalyzeService;
use App\Game\Exploration\Services\InvestigableWorldService;
use App\Game\Inventory\InventoryException;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class ExplorationController extends Controller
{
    public function listBiomeObjects(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $biomeCode = (string) ($params['biomeCode'] ?? '');

            $result = (new InvestigableWorldService())->listBiomeObjects((int) $player['id'], $biomeCode);

            $this->success($result, 'Exploration objects.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (ExplorationException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function listBiomes(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new InvestigableWorldService())->listBiomes((int) $player['id']);

            $this->success($result, 'Exploration biomes.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        }
    }

    public function updateBiomePosition(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'map_x' => 'required|numeric|min:0',
                'map_y' => 'required|numeric|min:0',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $biomeCode = (string) ($params['biomeCode'] ?? '');

            $result = (new InvestigableWorldService())->updatePlayerPosition(
                (int) $player['id'],
                $biomeCode,
                (float) $payload['map_x'],
                (float) $payload['map_y']
            );

            $this->success($result, 'Exploration position updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (ExplorationException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function analyzeObject(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'tool_item_public_id' => 'required|string|max:64',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $objectPublicId = (string) ($params['objectPublicId'] ?? '');

            $result = $this->transaction(fn (): array => (new ExplorationAnalyzeService())->analyzeMagnifier(
                (int) $player['id'],
                $objectPublicId,
                (string) $payload['tool_item_public_id']
            ));

            $this->success($result, 'Exploration object analyzed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (ExplorationException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function executeObjectAction(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'tool_item_public_id' => 'required|string|max:64',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $objectPublicId = (string) ($params['objectPublicId'] ?? '');
            $actionCode = (string) ($params['actionCode'] ?? '');

            $result = $this->transaction(fn (): array => (new ExplorationActionExecuteService())->execute(
                (int) $player['id'],
                $objectPublicId,
                $actionCode,
                (string) $payload['tool_item_public_id']
            ));

            $this->success($result, 'Exploration action executed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (ExplorationException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }
}
