<?php

namespace App\Game\Socketing\Services;

use App\Game\Enhancement\Services\PropertyScopeService;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;

class GemSocketCompatibilityService
{
    public function __construct(
        private ?ItemInstanceSocketRepository $sockets = null,
        private ?ItemInstancePropertyRepository $properties = null,
        private ?PropertyScopeService $scopes = null
    ) {
        $this->sockets ??= new ItemInstanceSocketRepository();
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->scopes ??= new PropertyScopeService();
    }

    public function preview(array $gem, array $target): array
    {
        $this->assertGemItem($gem);
        $this->assertNotSameItem($gem, $target);

        if (!$this->isSocketableEquipment($target)) {
            return $this->reject('SOCKET_TARGET_NOT_EQUIPMENT', 'Gemas so podem ser encaixadas em equipamentos.');
        }

        $emptySockets = $this->sockets->countEmpty((int) $target['id']);
        if ($emptySockets <= 0) {
            return $this->reject('SOCKET_NO_EMPTY_SLOT', 'Este item nao possui engastes vazios.');
        }

        $effect = $this->resolveGemEffect($gem);
        if ($effect === null) {
            return $this->reject('SOCKET_GEM_NO_EFFECT', 'Esta gema nao possui efeito de engaste configurado.');
        }

        $definition = $this->properties->findDefinitionByCode((string) $effect['property']);
        if ($definition === null) {
            return $this->reject('SOCKET_GEM_UNKNOWN_PROPERTY', 'A propriedade desta gema nao foi encontrada.');
        }

        $scope = (string) ($definition['equipment_scope'] ?? $this->scopes->defaultScope());
        if (!$this->scopes->isAllowedForCategory($scope, (string) ($target['category_code'] ?? ''))) {
            return $this->reject('SOCKET_GEM_SCOPE_MISMATCH', 'Esta gema nao combina com este tipo de equipamento.');
        }

        return [
            'can_apply' => true,
            'gem_code' => (string) $gem['definition_code'],
            'target_public_id' => (string) $target['public_id'],
            'empty_socket_count' => $emptySockets,
            'gem_effect' => $effect,
            'consumes_gem' => true,
        ];
    }

    public function assertCanApply(array $gem, array $target): void
    {
        $preview = $this->preview($gem, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            throw new InventoryException(
                (string) ($preview['reason_code'] ?? 'SOCKET_INCOMPATIBLE'),
                (string) ($preview['reason_message'] ?? 'Gem cannot be socketed into this item.'),
                422
            );
        }
    }

    public function resolveGemEffect(array $gem): ?array
    {
        $primaryCode = $this->primaryGemPropertyCode($gem);
        if ($primaryCode !== null) {
            $property = $this->properties->findByItemAndCode((int) $gem['id'], $primaryCode);
            if ($property !== null) {
                $value = ($property['value_type'] ?? '') === 'integer'
                    ? (int) ($property['integer_value'] ?? 0)
                    : (float) ($property['numeric_value'] ?? 0);

                return [
                    'property' => (string) $property['code'],
                    'property_name' => (string) $property['name'],
                    'value' => $value,
                ];
            }
        }

        $config = $this->parseBaseConfig($gem);
        $effect = $config['gem_effect'] ?? null;
        if (!is_array($effect)) {
            return null;
        }

        $propertyCode = (string) ($effect['property'] ?? '');
        if ($propertyCode === '') {
            return null;
        }

        $definition = $this->properties->findDefinitionByCode($propertyCode);

        return [
            'property' => $propertyCode,
            'property_name' => (string) ($definition['name'] ?? $propertyCode),
            'value' => (float) ($effect['value'] ?? 0),
        ];
    }

    private function primaryGemPropertyCode(array $gem): ?string
    {
        $config = $this->parseBaseConfig($gem);
        $effect = $config['gem_effect'] ?? null;

        return is_array($effect) ? (string) ($effect['property'] ?? '') ?: null : null;
    }

    private function assertGemItem(array $gem): void
    {
        $code = (string) ($gem['definition_code'] ?? '');
        if (!str_starts_with($code, 'gem_')) {
            throw new InventoryException('SOCKET_NOT_A_GEM', 'Selected item is not a socket gem.', 422);
        }

        if ((int) ($gem['quantity'] ?? 1) !== 1) {
            throw new InventoryException('SOCKET_GEM_NOT_INSTANCE', 'Gems must be unique item instances.', 422);
        }
    }

    private function assertNotSameItem(array $gem, array $target): void
    {
        if ((int) $gem['id'] === (int) $target['id']) {
            throw new InventoryException('SOCKET_SAME_ITEM', 'A gem cannot be socketed into itself.', 422);
        }
    }

    private function isSocketableEquipment(array $item): bool
    {
        if (empty($item['equip_slot_code'])) {
            return false;
        }

        return in_array((string) ($item['category_code'] ?? ''), ['weapon', 'armor', 'tool'], true);
    }

    private function parseBaseConfig(array $item): array
    {
        $raw = $item['base_config'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function reject(string $code, string $message): array
    {
        return [
            'can_apply' => false,
            'reason_code' => $code,
            'reason_message' => $message,
        ];
    }
}
