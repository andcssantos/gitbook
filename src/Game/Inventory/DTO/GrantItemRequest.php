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
        public readonly ?string $materialOriginCode,
        public readonly ?bool $preferExpeditionCarry = null
    ) {
    }

    public static function fromArray(int $playerId, array $data): self
    {
        $qualityValue = $data['quality_value'] ?? null;
        $preferCarry = $data['prefer_expedition_carry'] ?? null;

        return new self(
            $playerId,
            (string) ($data['item_definition_code'] ?? ''),
            (int) ($data['quantity'] ?? 1),
            isset($data['quality_bucket']) ? (string) $data['quality_bucket'] : null,
            $qualityValue !== null ? (float) $qualityValue : null,
            isset($data['material_origin_code']) ? (string) $data['material_origin_code'] : null,
            is_bool($preferCarry) ? $preferCarry : null
        );
    }
}
