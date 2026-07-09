<?php

namespace App\Game\Containers\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use PDO;

class PhysicalContainerLinkService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function ensureForItem(int $playerId, array $item, ?int $sortOrder = null): ?array
    {
        if ((int) ($item['is_container'] ?? 0) !== 1) {
            return null;
        }

        $containers = new ContainerRepository($this->pdo);
        $existing = $containers->findInstanceBySourceItemId((int) $item['id'], true);
        if ($existing !== null) {
            return $this->mapLinkedContainer($existing);
        }

        $definitionCode = $this->linkedContainerDefinitionCode($item);
        if ($definitionCode === null) {
            return null;
        }

        $definition = $containers->findDefinition($definitionCode);
        if ($definition === null) {
            throw new InventoryException(
                'ITEM_CONTAINER_DEFINITION_NOT_FOUND',
                'Linked container definition was not found for the item.',
                500
            );
        }

        $containers->createInstanceFromDefinition($definition, $playerId, [
            'source_item_instance_id' => (int) $item['id'],
            'sort_order' => $sortOrder ?? $this->nextSortOrder($containers, $playerId),
        ]);

        $linked = $containers->findInstanceBySourceItemId((int) $item['id'], true);
        if ($linked === null) {
            throw new InventoryException(
                'ITEM_CONTAINER_LINK_FAILED',
                'Linked container could not be created for the item.',
                500
            );
        }

        return $this->mapLinkedContainer($linked);
    }

    public function linkedContainerDefinitionCode(array $item): ?string
    {
        $config = $item['base_config'] ?? null;
        if ($config === null || $config === '') {
            return null;
        }

        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (!is_array($decoded)) {
                return null;
            }

            $config = $decoded;
        }

        if (!is_array($config)) {
            return null;
        }

        $code = $config['container_definition'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function nextSortOrder(ContainerRepository $containers, int $playerId): int
    {
        $max = 0;
        foreach ($containers->listActiveInstancesForPlayer($playerId) as $container) {
            $max = max($max, (int) ($container['sort_order'] ?? 0));
        }

        return $max + 10;
    }

    private function mapLinkedContainer(array $container): array
    {
        return [
            'public_id' => (string) $container['public_id'],
            'definition_code' => (string) ($container['definition_code'] ?? ''),
            'name' => (string) $container['name'],
            'source_item_instance_id' => (int) $container['source_item_instance_id'],
        ];
    }
}
