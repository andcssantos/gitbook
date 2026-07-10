<?php

namespace App\Game\Socketing\DTO;

class ApplyGemSocketRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $gemItemPublicId,
        public readonly string $targetItemPublicId,
        public readonly int $expectedGemPlacementVersion,
        public readonly int $expectedTargetPlacementVersion,
        public readonly bool $confirmed
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        return new self(
            $playerId,
            (string) ($data['gem_item_public_id'] ?? ''),
            (string) ($data['target_item_public_id'] ?? ''),
            (int) ($data['expected_gem_placement_version'] ?? 0),
            (int) ($data['expected_target_placement_version'] ?? 0),
            filter_var($data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }
}
