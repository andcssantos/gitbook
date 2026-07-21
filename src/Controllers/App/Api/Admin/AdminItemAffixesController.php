<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminItemAffixDefinitionService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminItemAffixesController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminItemAffixDefinitionService())->meta(), 'Admin affix meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminItemAffixDefinitionService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'affix_type' => (string) ($query['affix_type'] ?? ''),
                'property_code' => (string) ($query['property_code'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin affix definitions listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $definition = (new AdminItemAffixDefinitionService())->getByCode($code);
            $this->success(['definition' => $definition], 'Admin affix definition loaded.');
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
                'affix_type' => 'nullable|string|max:20',
                'property_code' => 'required|string|max:80',
                'min_value' => 'nullable|numeric',
                'max_value' => 'nullable|numeric',
                'rarity_weight' => 'nullable|int|min:1',
                'min_item_level' => 'nullable|int|min:1',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminItemAffixDefinitionService())->create($payload);
            $this->audit('admin.affix.create', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin affix definition created.', 201);
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
                'affix_type' => 'nullable|string|max:20',
                'property_code' => 'required|string|max:80',
                'min_value' => 'nullable|numeric',
                'max_value' => 'nullable|numeric',
                'rarity_weight' => 'nullable|int|min:1',
                'min_item_level' => 'nullable|int|min:1',
                'status' => 'nullable|string|max:20',
            ]);

            $definition = (new AdminItemAffixDefinitionService())->update($code, $payload);
            $this->audit('admin.affix.update', ['code' => $definition['code']]);
            $this->success(['definition' => $definition], 'Admin affix definition updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
