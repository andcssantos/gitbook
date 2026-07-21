<?php

namespace App\Game\Inventory\Services;

use App\Game\Expeditions\Services\ExpeditionStateService;
use App\Support\DB;
use PDO;

class ExpeditionLootPlacementPreferenceService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function shouldPreferCarry(int $playerId, ?bool $explicit = null): bool
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $state = new ExpeditionStateService($this->pdo());
        if (!$state->hasActiveForPlayer($playerId)) {
            return false;
        }

        $active = $state->activeForPlayer($playerId);
        if ($active === null) {
            return false;
        }

        $metadata = $this->parseJson($active['metadata_json'] ?? null);

        return ($metadata['auto_carry_loot'] ?? true) !== false;
    }

    /** @return array<string, mixed> */
    private function parseJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
