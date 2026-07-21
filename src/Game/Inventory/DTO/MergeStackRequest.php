<?php

namespace App\Game\Inventory\DTO;

class MergeStackRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $sourceItemPublicId,
        public readonly string $targetItemPublicId,
        public readonly int $quantity,
        public readonly ?int $expectedSourcePlacementVersion = null,
        public readonly ?int $expectedTargetPlacementVersion = null
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        $hasSourceVersion = array_key_exists('expected_source_placement_version', $data)
            || array_key_exists('expected_placement_version', $data);
        $hasTargetVersion = array_key_exists('expected_target_placement_version', $data);

        return new self(
            $playerId,
            (string) ($data['source_item_public_id'] ?? ''),
            (string) ($data['target_item_public_id'] ?? ''),
            (int) ($data['quantity'] ?? 0),
            $hasSourceVersion
                ? (int) ($data['expected_source_placement_version'] ?? $data['expected_placement_version'] ?? 0)
                : null,
            $hasTargetVersion
                ? (int) ($data['expected_target_placement_version'] ?? 0)
                : null
        );
    }
}
