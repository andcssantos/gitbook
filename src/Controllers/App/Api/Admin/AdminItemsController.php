<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminItemDefinitionService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminItemsController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminItemDefinitionService())->meta(), 'Admin item meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminItemDefinitionService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'category_code' => (string) ($query['category_code'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin item definitions listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $item = (new AdminItemDefinitionService())->getByCode($code);
            $this->success(['item' => $item], 'Admin item definition loaded.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function store(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $payload = $this->validate(Request::body(), [
                'code' => 'required|string|max:100',
                'name' => 'required|string|max:120',
                'description' => 'nullable|string|max:4000',
                'category_code' => 'required|string|max:80',
                'material_family_code' => 'nullable|string|max:80',
                'stackable' => 'nullable|boolean',
                'max_stack' => 'nullable|int|min:1',
                'grid_w' => 'nullable|int|min:1',
                'grid_h' => 'nullable|int|min:1',
                'equip_slot_code' => 'nullable|string|max:40',
                'is_container' => 'nullable|boolean',
                'tradeable' => 'nullable|boolean',
                'status' => 'nullable|string|max:20',
                'base_config' => 'nullable|array',
            ]);

            $item = (new AdminItemDefinitionService())->create($payload);
            $this->audit('admin.item.create', ['code' => $item['code']]);
            $this->success(['item' => $item], 'Admin item definition created.', 201);
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
                'description' => 'nullable|string|max:4000',
                'category_code' => 'required|string|max:80',
                'material_family_code' => 'nullable|string|max:80',
                'stackable' => 'nullable|boolean',
                'max_stack' => 'nullable|int|min:1',
                'grid_w' => 'nullable|int|min:1',
                'grid_h' => 'nullable|int|min:1',
                'equip_slot_code' => 'nullable|string|max:40',
                'is_container' => 'nullable|boolean',
                'tradeable' => 'nullable|boolean',
                'status' => 'nullable|string|max:20',
                'base_config' => 'nullable|array',
            ]);

            $item = (new AdminItemDefinitionService())->update($code, $payload);
            $this->audit('admin.item.update', ['code' => $item['code']]);
            $this->success(['item' => $item], 'Admin item definition updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
