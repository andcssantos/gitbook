<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminInvestigableService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminInvestigablesController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminInvestigableService())->meta(), 'Admin investigable meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminInvestigableService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'biome_code' => (string) ($query['biome_code'] ?? ''),
                'is_active' => $query['is_active'] ?? '',
                'limit' => (int) ($query['limit'] ?? 80),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin investigables listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $definition = (new AdminInvestigableService())->getByCode((string) ($params['code'] ?? ''));
            $this->success(['definition' => $definition], 'Admin investigable loaded.');
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
                'biome_code' => 'required|string|max:60',
                'kind' => 'nullable|string|max:40',
                'summary' => 'nullable|string|max:255',
                'icon_key' => 'nullable|string|max:60',
                'sort_order' => 'nullable|int|min:0',
                'is_active' => 'nullable|boolean',
                'config' => 'nullable|array',
                'actions' => 'nullable|array',
            ]);
            $definition = (new AdminInvestigableService())->create($payload);
            $this->audit('admin.investigable.create', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin investigable created.', 201);
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
                'biome_code' => 'required|string|max:60',
                'kind' => 'nullable|string|max:40',
                'summary' => 'nullable|string|max:255',
                'icon_key' => 'nullable|string|max:60',
                'sort_order' => 'nullable|int|min:0',
                'is_active' => 'nullable|boolean',
                'config' => 'nullable|array',
                'actions' => 'nullable|array',
            ]);
            $definition = (new AdminInvestigableService())->update($code, $payload);
            $this->audit('admin.investigable.update', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin investigable updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function upsertAction(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $payload = $this->validate(Request::body(), [
                'action_code' => 'required|string|max:80',
                'required_tool_type' => 'nullable|string|max:60',
                'min_reveal_tier' => 'nullable|int|min:0',
                'max_reveal_tier' => 'nullable|int|min:0',
                'xp_tool' => 'nullable|int|min:0',
                'xp_attribute' => 'nullable|int|min:0',
                'attribute_code' => 'nullable|string|max:60',
                'sort_order' => 'nullable|int|min:0',
                'is_active' => 'nullable|boolean',
                'config' => 'nullable|array',
            ]);
            $definition = (new AdminInvestigableService())->upsertAction($code, $payload);
            $this->audit('admin.investigable.action.upsert', [
                'code' => $code,
                'action_code' => $payload['action_code'],
            ]);
            $this->success(['definition' => $definition], 'Admin investigable action upserted.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function deleteAction(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $actionCode = (string) ($params['action_code'] ?? '');
            $definition = (new AdminInvestigableService())->deleteAction($code, $actionCode);
            $this->audit('admin.investigable.action.delete', [
                'code' => $code,
                'action_code' => $actionCode,
            ]);
            $this->success(['definition' => $definition], 'Admin investigable action deleted.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
