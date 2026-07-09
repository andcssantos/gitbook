<?php

namespace App\Game\Inventory\DTO;

class GrantItemRequest
{
    public function __construct(
        public readonly int $playerId,
        public readonly string $itemDefinitionCode,
        public readonly int $quantity,
        public readonly ?string $qualityBucket,
        public readonly ?float $qualityValue,
        public readonly ?string $materialOriginCode
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        $qualityValue = $data['quality_value'] ?? null;

        return new self(
            $playerId,
            (string) ($data['item_definition_code'] ?? ''),
            (int) ($data['quantity'] ?? 1),
            isset($data['quality_bucket']) ? (string) $data['quality_bucket'] : null,
            $qualityValue !== null ? (float) $qualityValue : null,
            isset($data['material_origin_code']) ? (string) $data['material_origin_code'] : null
        );
    }
}
