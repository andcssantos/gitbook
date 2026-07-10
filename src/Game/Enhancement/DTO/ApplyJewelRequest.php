<?php

namespace App\Game\Enhancement\DTO;

class ApplyJewelRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $jewelItemPublicId,
        public readonly string $targetItemPublicId,
        public readonly int $expectedJewelPlacementVersion,
        public readonly int $expectedTargetPlacementVersion,
        public readonly bool $confirmed
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        return new self(
            $playerId,
            (string) ($data['jewel_item_public_id'] ?? ''),
            (string) ($data['target_item_public_id'] ?? ''),
            (int) ($data['expected_jewel_placement_version'] ?? 0),
            (int) ($data['expected_target_placement_version'] ?? 0),
            filter_var($data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }
}
