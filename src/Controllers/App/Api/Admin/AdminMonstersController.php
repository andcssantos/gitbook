<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminMonsterDefinitionService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminMonstersController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminMonsterDefinitionService())->meta(), 'Admin monster meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminMonsterDefinitionService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin monsters listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $definition = (new AdminMonsterDefinitionService())->getByCode((string) ($params['code'] ?? ''));
            $this->success(['definition' => $definition], 'Admin monster loaded.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function store(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $payload = $this->validate(Request::body(), [
                'code' => 'required|string|max:80',
                'name' => 'required|string|max:120',
                'sprite_key' => 'nullable|string|max:60',
                'element' => 'nullable|string|max:40',
                'resistance' => 'nullable|string|max:40',
                'base_hp' => 'nullable|int|min:1',
                'base_attack' => 'nullable|int|min:0',
                'base_defense' => 'nullable|int|min:0',
                'dodge_rate' => 'nullable|numeric',
                'attack_rate' => 'nullable|numeric',
                'crit_rate' => 'nullable|numeric',
                'reward_gold_min' => 'nullable|int|min:0',
                'reward_gold_max' => 'nullable|int|min:0',
                'reward_xp_min' => 'nullable|int|min:0',
                'reward_xp_max' => 'nullable|int|min:0',
                'loot' => 'nullable|array',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminMonsterDefinitionService())->create($payload);
            $this->audit('admin.monster.create', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin monster created.', 201);
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function update(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $payload = $this->validate(Request::body(), [
                'name' => 'required|string|max:120',
                'sprite_key' => 'nullable|string|max:60',
                'element' => 'nullable|string|max:40',
                'resistance' => 'nullable|string|max:40',
                'base_hp' => 'nullable|int|min:1',
                'base_attack' => 'nullable|int|min:0',
                'base_defense' => 'nullable|int|min:0',
                'dodge_rate' => 'nullable|numeric',
                'attack_rate' => 'nullable|numeric',
                'crit_rate' => 'nullable|numeric',
                'reward_gold_min' => 'nullable|int|min:0',
                'reward_gold_max' => 'nullable|int|min:0',
                'reward_xp_min' => 'nullable|int|min:0',
                'reward_xp_max' => 'nullable|int|min:0',
                'loot' => 'nullable|array',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminMonsterDefinitionService())->update($code, $payload);
            $this->audit('admin.monster.update', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin monster updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
