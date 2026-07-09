<?php

namespace App\Game\Inventory\DTO;

class MergeStackRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $sourceItemPublicId,
        public readonly string $targetItemPublicId,
        public readonly int $quantity
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        return new self(
            $playerId,
            (string) ($data['source_item_public_id'] ?? ''),
            (string) ($data['target_item_public_id'] ?? ''),
            (int) ($data['quantity'] ?? 0)
        );
    }
}
