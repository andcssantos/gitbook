<?php

namespace App\Game\Containers\Repositories;

use PDO;

class ContainerRepository
{
    private ContainerDefinitionRepository $definitions;
    private ContainerInstanceRepository $instances;
    private ContainerItemRepository $items;

    public function __construct(?PDO $pdo = null)
    {
        $this->definitions = new ContainerDefinitionRepository($pdo);
        $this->instances = new ContainerInstanceRepository($pdo);
        $this->items = new ContainerItemRepository($pdo);
    }

    public function findDefinition(string $code): ?array
    {
        return $this->definitions->findActiveByCode($code);
    }

    public function findInstanceForPlayer(int $playerId, string $definitionCode): ?array
    {
        return $this->instances->findByOwnerAndDefinitionCode($playerId, $definitionCode);
    }

    public function findInstanceByPublicIdForPlayer(string $publicId, int $playerId, bool $lock = false): ?array
    {
        return $this->instances->findByPublicIdAndOwner($publicId, $playerId, $lock);
    }

    public function findInstanceByPublicId(string $publicId): ?array
    {
        return $this->instances->findByPublicId($publicId);
    }

    public function createInstanceFromDefinition(array $definition, int $playerId, array $overrides = []): int
    {
        return $this->instances->create(array_merge([
            'container_definition_id' => (int) $definition['id'],
            'owner_player_id' => $playerId,
            'name' => (string) $definition['name'],
            'grid_columns' => (int) $definition['grid_columns'],
            'grid_rows' => (int) $definition['grid_rows'],
        ], $overrides));
    }

    public function countItems(int $containerId): int
    {
        return $this->items->countByContainerId($containerId);
    }

    public function placeItem(array $placement): int
    {
        return $this->items->place($placement);
    }

    public function findPlacement(int $itemInstanceId, int $containerInstanceId, bool $lock = false): ?array
    {
        return $this->items->findByItemAndContainer($itemInstanceId, $containerInstanceId, $lock);
    }

    public function listPlacements(int $containerId, bool $lock = false): array
    {
        return $this->items->listByContainerId($containerId, $lock);
    }

    public function updatePlacement(int $placementId, array $data): void
    {
        $this->items->updatePlacement($placementId, $data);
    }

    public function findPlacementById(int $id): ?array
    {
        return $this->items->findById($id);
    }

    public function findPlacementByItemId(int $itemInstanceId, bool $lock = false): ?array
    {
        return $this->items->findByItemId($itemInstanceId, $lock);
    }

    public function deletePlacementByItemId(int $itemInstanceId): void
    {
        $this->items->deleteByItemId($itemInstanceId);
    }
}
