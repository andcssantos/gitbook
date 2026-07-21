<?php

namespace App\Game\Crafting\Repositories;

use App\Support\DB;
use PDO;
use Throwable;

class CraftRecipeCatalogRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function hasTables(): bool
    {
        try {
            $this->pdo()->query('SELECT 1 FROM craft_recipes LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function countRecipes(): int
    {
        if (!$this->hasTables()) {
            return 0;
        }

        return (int) $this->pdo()->query('SELECT COUNT(*) FROM craft_recipes')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function allActive(): array
    {
        $rows = $this->pdo()->query(
            "SELECT * FROM craft_recipes WHERE status = 'active' ORDER BY sort_order ASC, code ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $row): array => $this->mapRecipe($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM craft_recipes WHERE code = :code AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRecipe($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRecipe(array $row): array
    {
        $recipeId = (int) $row['id'];

        $reqStmt = $this->pdo()->prepare(
            'SELECT * FROM craft_recipe_requirements WHERE craft_recipe_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $reqStmt->execute(['id' => $recipeId]);
        $requirements = [];
        foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $requirement) {
            $mapped = [
                'kind' => (string) ($requirement['kind'] ?? ''),
                'min' => (int) ($requirement['min_quantity'] ?? 1),
                'weight' => (int) ($requirement['weight'] ?? 1),
            ];
            if ($requirement['label'] !== null && $requirement['label'] !== '') {
                $mapped['label'] = (string) $requirement['label'];
            }
            if ($requirement['item_definition_code'] !== null && $requirement['item_definition_code'] !== '') {
                $mapped['definition_code'] = (string) $requirement['item_definition_code'];
            }
            if ($requirement['material_family_code'] !== null && $requirement['material_family_code'] !== '') {
                $mapped['family_code'] = (string) $requirement['material_family_code'];
            }
            if ($requirement['material_origin_code'] !== null && $requirement['material_origin_code'] !== '') {
                $mapped['origin_code'] = (string) $requirement['material_origin_code'];
            }
            $originCodes = $this->decodeJson($requirement['origin_codes_json'] ?? null);
            if (is_array($originCodes)) {
                $mapped['origin_codes'] = $originCodes;
            }
            $extra = $this->decodeJson($requirement['extra_json'] ?? null);
            if (is_array($extra)) {
                $mapped = array_merge($mapped, $extra);
            }
            $requirements[] = $mapped;
        }

        $outStmt = $this->pdo()->prepare(
            'SELECT * FROM craft_recipe_outputs WHERE craft_recipe_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $outStmt->execute(['id' => $recipeId]);
        $outputs = [];
        foreach ($outStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $output) {
            $mapped = [
                'definition_code' => (string) ($output['item_definition_code'] ?? ''),
                'quality_bucket' => (string) ($output['quality_bucket'] ?? 'common'),
                'weight' => (int) ($output['weight'] ?? 1),
                'quantity' => (int) ($output['quantity'] ?? 1),
            ];
            if ($output['name_override'] !== null && $output['name_override'] !== '') {
                $mapped['name'] = (string) $output['name_override'];
            }
            $extra = $this->decodeJson($output['extra_json'] ?? null);
            if (is_array($extra)) {
                $mapped = array_merge($mapped, $extra);
            }
            $outputs[] = $mapped;
        }

        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'workspace' => (string) $row['workspace_code'],
            'discovery' => (string) ($row['discovery'] ?? 'public'),
            'gold_fee' => (int) ($row['gold_fee'] ?? 0),
            'description' => (string) ($row['description'] ?? ''),
            'requirements' => $requirements,
            'outputs' => $outputs,
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));

        return preg_replace('/[^a-z0-9_\-]/', '', $code) ?: '';
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
