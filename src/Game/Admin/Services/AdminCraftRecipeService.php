<?php

namespace App\Game\Admin\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use App\Utils\Config;
use PDO;
use Throwable;

class AdminCraftRecipeService
{
    private const STATUSES = ['active', 'inactive', 'draft'];
    private const DISCOVERIES = ['public', 'hidden'];
    private const KINDS = [
        'item_definition',
        'material_family',
        'material_origin',
        'material_origin_any',
        'material_origin_any_or_family',
    ];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{recipes: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $workspace = trim((string) ($filters['workspace'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q OR COALESCE(description, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($workspace !== '') {
            $where[] = 'workspace_code = :workspace';
            $params['workspace'] = $workspace;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM craft_recipes WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM craft_recipes WHERE {$whereSql} ORDER BY sort_order ASC, code ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'recipes' => array_map(fn (array $row): array => $this->mapSummary($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function getByCode(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM craft_recipes WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_RECIPE_NOT_FOUND', 'Craft recipe was not found.', 404);
        }

        return $this->mapFull($row);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->transaction(function () use ($payload): array {
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                throw new InventoryException('ADMIN_RECIPE_INVALID', 'Recipe code is required.', 422);
            }
            $existing = $this->pdo()->prepare('SELECT id FROM craft_recipes WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_RECIPE_CODE_EXISTS', 'Recipe code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $this->pdo()->prepare(
                'INSERT INTO craft_recipes (
                    code, name, workspace_code, discovery, gold_fee, description, status, sort_order
                ) VALUES (
                    :code, :name, :workspace_code, :discovery, :gold_fee, :description, :status, :sort_order
                )'
            )->execute(['code' => $code, ...$fields]);
            $recipeId = (int) $this->pdo()->lastInsertId();
            $this->replaceRequirements($recipeId, $payload['requirements'] ?? []);
            $this->replaceOutputs($recipeId, $payload['outputs'] ?? []);

            return $this->getByCode($code);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $code, array $payload): array
    {
        return $this->transaction(function () use ($code, $payload): array {
            $normalized = $this->normalizeCode($code);
            $current = $this->getByCode($normalized);
            $fields = $this->normalizeWritableFields($payload, false, $current);

            $this->pdo()->prepare(
                'UPDATE craft_recipes SET
                    name = :name, workspace_code = :workspace_code, discovery = :discovery,
                    gold_fee = :gold_fee, description = :description, status = :status,
                    sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE code = :code'
            )->execute(['code' => $normalized, ...$fields]);

            if (array_key_exists('requirements', $payload)) {
                $this->replaceRequirements((int) $current['id'], $payload['requirements']);
            }
            if (array_key_exists('outputs', $payload)) {
                $this->replaceOutputs((int) $current['id'], $payload['outputs']);
            }

            return $this->getByCode($normalized);
        });
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        $workspaces = array_keys((array) Config::get('crafting.workspaces', ['forge' => [], 'alchemy' => []]));
        $families = [];
        try {
            $families = $this->pdo()->query('SELECT code, name FROM material_families ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $families = [];
        }

        return [
            'statuses' => self::STATUSES,
            'discoveries' => self::DISCOVERIES,
            'requirement_kinds' => self::KINDS,
            'workspaces' => array_values(array_map('strval', $workspaces)),
            'quality_buckets' => ['common', 'uncommon', 'magic', 'rare', 'epic'],
            'material_families' => array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
            ], $families),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'workspace' => (string) $row['workspace_code'],
            'discovery' => (string) ($row['discovery'] ?? 'public'),
            'gold_fee' => (int) ($row['gold_fee'] ?? 0),
            'status' => (string) ($row['status'] ?? 'active'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapFull(array $row): array
    {
        $recipeId = (int) $row['id'];
        $reqStmt = $this->pdo()->prepare(
            'SELECT * FROM craft_recipe_requirements WHERE craft_recipe_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $reqStmt->execute(['id' => $recipeId]);
        $requirements = [];
        foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $requirement) {
            $mapped = [
                'kind' => (string) $requirement['kind'],
                'min' => (int) $requirement['min_quantity'],
                'weight' => (int) $requirement['weight'],
                'label' => $requirement['label'] !== null ? (string) $requirement['label'] : null,
            ];
            if ($requirement['item_definition_code']) {
                $mapped['definition_code'] = (string) $requirement['item_definition_code'];
            }
            if ($requirement['material_family_code']) {
                $mapped['family_code'] = (string) $requirement['material_family_code'];
            }
            if ($requirement['material_origin_code']) {
                $mapped['origin_code'] = (string) $requirement['material_origin_code'];
            }
            $originCodes = $this->decodeJson($requirement['origin_codes_json'] ?? null);
            if (is_array($originCodes)) {
                $mapped['origin_codes'] = $originCodes;
            }
            $requirements[] = $mapped;
        }

        $outStmt = $this->pdo()->prepare(
            'SELECT * FROM craft_recipe_outputs WHERE craft_recipe_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $outStmt->execute(['id' => $recipeId]);
        $outputs = [];
        foreach ($outStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $output) {
            $outputs[] = [
                'definition_code' => (string) $output['item_definition_code'],
                'name' => $output['name_override'] !== null ? (string) $output['name_override'] : null,
                'quality_bucket' => (string) ($output['quality_bucket'] ?? 'common'),
                'weight' => (int) ($output['weight'] ?? 1),
                'quantity' => (int) ($output['quantity'] ?? 1),
            ];
        }

        return [
            'id' => $recipeId,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'workspace' => (string) $row['workspace_code'],
            'discovery' => (string) ($row['discovery'] ?? 'public'),
            'gold_fee' => (int) ($row['gold_fee'] ?? 0),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'status' => (string) ($row['status'] ?? 'active'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'requirements' => $requirements,
            'outputs' => $outputs,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizeWritableFields(array $payload, bool $creating, ?array $current = null): array
    {
        $name = trim((string) ($payload['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            throw new InventoryException('ADMIN_RECIPE_INVALID', 'Recipe name is required.', 422);
        }

        $workspace = strtolower(trim((string) ($payload['workspace'] ?? $payload['workspace_code'] ?? ($current['workspace'] ?? 'forge'))));
        $discovery = strtolower(trim((string) ($payload['discovery'] ?? ($current['discovery'] ?? 'public'))));
        if (!in_array($discovery, self::DISCOVERIES, true)) {
            throw new InventoryException('ADMIN_RECIPE_INVALID', 'Invalid discovery.', 422);
        }

        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InventoryException('ADMIN_RECIPE_INVALID', 'Invalid status.', 422);
        }

        $description = array_key_exists('description', $payload)
            ? trim((string) ($payload['description'] ?? ''))
            : (string) ($current['description'] ?? '');
        $description = $description !== '' ? $description : null;

        return [
            'name' => $name,
            'workspace_code' => $workspace !== '' ? $workspace : 'forge',
            'discovery' => $discovery,
            'gold_fee' => max(0, (int) ($payload['gold_fee'] ?? ($current['gold_fee'] ?? 0))),
            'description' => $description,
            'status' => $status,
            'sort_order' => (int) ($payload['sort_order'] ?? ($current['sort_order'] ?? 0)),
        ];
    }

    private function replaceRequirements(int $recipeId, mixed $requirements): void
    {
        $requirements = $this->parseArrayField($requirements, 'requirements');
        $this->pdo()->prepare('DELETE FROM craft_recipe_requirements WHERE craft_recipe_id = :id')->execute(['id' => $recipeId]);
        $insert = $this->pdo()->prepare(
            'INSERT INTO craft_recipe_requirements (
                craft_recipe_id, sort_order, kind, min_quantity, label, weight,
                item_definition_code, material_family_code, material_origin_code, origin_codes_json
            ) VALUES (
                :craft_recipe_id, :sort_order, :kind, :min_quantity, :label, :weight,
                :item_definition_code, :material_family_code, :material_origin_code, :origin_codes_json
            )'
        );

        foreach (array_values($requirements) as $index => $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $kind = strtolower(trim((string) ($requirement['kind'] ?? '')));
            if ($kind === '' || !in_array($kind, self::KINDS, true)) {
                throw new InventoryException('ADMIN_RECIPE_INVALID', 'Invalid requirement kind.', 422);
            }
            $originCodes = $requirement['origin_codes'] ?? null;
            $insert->execute([
                'craft_recipe_id' => $recipeId,
                'sort_order' => (int) ($requirement['sort_order'] ?? (($index + 1) * 10)),
                'kind' => $kind,
                'min_quantity' => max(1, (int) ($requirement['min'] ?? $requirement['min_quantity'] ?? 1)),
                'label' => isset($requirement['label']) ? (string) $requirement['label'] : null,
                'weight' => max(1, (int) ($requirement['weight'] ?? 1)),
                'item_definition_code' => $requirement['definition_code'] ?? $requirement['item_definition_code'] ?? null,
                'material_family_code' => $requirement['family_code'] ?? $requirement['material_family_code'] ?? null,
                'material_origin_code' => $requirement['origin_code'] ?? $requirement['material_origin_code'] ?? null,
                'origin_codes_json' => is_array($originCodes)
                    ? json_encode($originCodes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    : null,
            ]);
        }
    }

    private function replaceOutputs(int $recipeId, mixed $outputs): void
    {
        $outputs = $this->parseArrayField($outputs, 'outputs');
        if ($outputs === []) {
            throw new InventoryException('ADMIN_RECIPE_INVALID', 'At least one output is required.', 422);
        }
        $this->pdo()->prepare('DELETE FROM craft_recipe_outputs WHERE craft_recipe_id = :id')->execute(['id' => $recipeId]);
        $insert = $this->pdo()->prepare(
            'INSERT INTO craft_recipe_outputs (
                craft_recipe_id, sort_order, item_definition_code, name_override, quality_bucket, weight, quantity
            ) VALUES (
                :craft_recipe_id, :sort_order, :item_definition_code, :name_override, :quality_bucket, :weight, :quantity
            )'
        );

        foreach (array_values($outputs) as $index => $output) {
            if (!is_array($output)) {
                continue;
            }
            $definitionCode = trim((string) ($output['definition_code'] ?? $output['item_definition_code'] ?? ''));
            if ($definitionCode === '') {
                throw new InventoryException('ADMIN_RECIPE_INVALID', 'Output definition_code is required.', 422);
            }
            $insert->execute([
                'craft_recipe_id' => $recipeId,
                'sort_order' => (int) ($output['sort_order'] ?? (($index + 1) * 10)),
                'item_definition_code' => $definitionCode,
                'name_override' => isset($output['name']) ? (string) $output['name'] : null,
                'quality_bucket' => (string) ($output['quality_bucket'] ?? 'common'),
                'weight' => max(1, (int) ($output['weight'] ?? 1)),
                'quantity' => max(1, (int) ($output['quantity'] ?? 1)),
            ]);
        }
    }

    /** @return list<mixed> */
    private function parseArrayField(mixed $value, string $field): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InventoryException('ADMIN_RECIPE_INVALID', "{$field} must be valid JSON.", 422);
            }
        }
        if (!is_array($value)) {
            throw new InventoryException('ADMIN_RECIPE_INVALID', "{$field} must be an array.", 422);
        }

        return array_values($value);
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

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo instanceof PDO) {
            $started = !$this->pdo->inTransaction();
            if ($started) {
                $this->pdo->beginTransaction();
            }
            try {
                $result = $callback();
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        return DB::transaction(fn (): mixed => $callback());
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
