<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminItemPropertyDefinitionService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminItemPropertiesController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminItemPropertyDefinitionService())->meta(), 'Admin property meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminItemPropertyDefinitionService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'value_type' => (string) ($query['value_type'] ?? ''),
                'equipment_scope' => (string) ($query['equipment_scope'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin property definitions listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $definition = (new AdminItemPropertyDefinitionService())->getByCode($code);
            $this->success(['definition' => $definition], 'Admin property definition loaded.');
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
                'value_type' => 'nullable|string|max:20',
                'unit' => 'nullable|string|max:40',
                'min_value' => 'nullable|numeric',
                'max_value' => 'nullable|numeric',
                'market_filterable' => 'nullable|boolean',
                'equipment_scope' => 'nullable|string|max:30',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminItemPropertyDefinitionService())->create($payload);
            $this->audit('admin.property.create', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin property definition created.', 201);
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
                'value_type' => 'nullable|string|max:20',
                'unit' => 'nullable|string|max:40',
                'min_value' => 'nullable|numeric',
                'max_value' => 'nullable|numeric',
                'market_filterable' => 'nullable|boolean',
                'equipment_scope' => 'nullable|string|max:30',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminItemPropertyDefinitionService())->update($code, $payload);
            $this->audit('admin.property.update', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin property definition updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
