<?php

namespace App\Game\Exploration\Support;

use App\Game\Exploration\ExplorationException;
use App\Support\DB;
use PDO;

class ExplorationToolSupport
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array<string, mixed> */
    public function resolveOwnedTool(int $playerId, string $toolItemPublicId, string $requiredToolType): array
    {
        $toolItemPublicId = trim($toolItemPublicId);
        if ($toolItemPublicId === '') {
            throw new ExplorationException('EXPLORATION_TOOL_REQUIRED', 'A tool is required for this action.', 422, [
                'required_tool_type' => $requiredToolType,
            ]);
        }

        $stmt = $this->pdo()->prepare('SELECT ii.*, id.code AS definition_code, id.base_config, ic.code AS category_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            WHERE ii.public_id = :public_id AND ii.owner_player_id = :player_id
            LIMIT 1');
        $stmt->execute([
            'public_id' => $toolItemPublicId,
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new ExplorationException('EXPLORATION_TOOL_NOT_FOUND', 'Tool item was not found in your inventory.', 404, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        if (($row['category_code'] ?? null) !== 'tool') {
            throw new ExplorationException('EXPLORATION_TOOL_INVALID', 'Selected item is not a tool.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        $toolType = $this->toolTypeFromItem($row);
        if ($toolType !== $requiredToolType) {
            throw new ExplorationException('EXPLORATION_TOOL_INVALID', 'Selected tool cannot perform this action.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
                'tool_type' => $toolType,
                'required_tool_type' => $requiredToolType,
            ]);
        }

        if ($row['current_durability'] !== null && (int) $row['current_durability'] <= 0) {
            throw new ExplorationException('EXPLORATION_TOOL_BROKEN', 'This tool is broken and must be repaired or replaced.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        $row['tool_type'] = $toolType;

        return $row;
    }

    private function toolTypeFromItem(array $item): string
    {
        $config = $this->parseJson($item['base_config'] ?? null);
        $toolType = strtolower(trim((string) ($config['tool_type'] ?? $config['tool_family'] ?? '')));

        return preg_replace('/[^a-z0-9_]/', '', $toolType) ?: '';
    }

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
