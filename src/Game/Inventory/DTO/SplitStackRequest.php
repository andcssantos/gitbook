<?php

namespace App\Game\Inventory\DTO;

class SplitStackRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $sourceItemPublicId,
        public readonly string $sourceContainerPublicId,
        public readonly string $targetContainerPublicId,
        public readonly int $quantity,
        public readonly int $gridX,
        public readonly int $gridY,
        public readonly int $expectedPlacementVersion
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        return new self(
            $playerId,
            (string) ($data['source_item_public_id'] ?? ''),
            (string) ($data['source_container_public_id'] ?? ''),
            (string) ($data['target_container_public_id'] ?? ''),
            (int) ($data['quantity'] ?? 0),
            (int) ($data['grid_x'] ?? -1),
            (int) ($data['grid_y'] ?? -1),
            (int) ($data['expected_placement_version'] ?? 0)
        );
    }
}
