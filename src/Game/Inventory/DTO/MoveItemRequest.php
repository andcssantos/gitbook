<?php

namespace App\Game\Inventory\DTO;

class MoveItemRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $itemPublicId,
        public readonly string $sourceContainerPublicId,
        public readonly string $targetContainerPublicId,
        public readonly int $gridX,
        public readonly int $gridY,
        public readonly bool $rotated,
        public readonly int $expectedPlacementVersion
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        return new self(
            $playerId,
            (string) ($data['item_public_id'] ?? ''),
            (string) ($data['source_container_public_id'] ?? ''),
            (string) ($data['target_container_public_id'] ?? ''),
            (int) ($data['grid_x'] ?? -1),
            (int) ($data['grid_y'] ?? -1),
            filter_var($data['rotated'] ?? false, FILTER_VALIDATE_BOOLEAN),
            (int) ($data['expected_placement_version'] ?? 0)
        );
    }
}
