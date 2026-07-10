<?php

namespace App\Game\Materials\Services;

use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Support\DB;
use App\Utils\Config;
use PDO;

class MaterialCompositionResolver
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemMaterialCompositionRepository $compositions = null
    ) {
        $this->compositions ??= new ItemMaterialCompositionRepository($this->pdo);
    }

    public function resolveForItem(array $item, bool $persistIfMissing = false): array
    {
        $itemInstanceId = (int) ($item['item_instance_id'] ?? $item['id'] ?? 0);
        if ($itemInstanceId <= 0) {
            return [];
        }

        $rows = $this->enrichRows($this->compositions->listForItem($itemInstanceId));
        if ($rows !== []) {
            return $rows;
        }

        $fallback = $this->buildFallback($item);
        if ($fallback === []) {
            return [];
        }

        if ($persistIfMissing) {
            $this->compositions->replaceForItem($itemInstanceId, array_map(fn (array $row): array => [
                'material_family_id' => (int) $row['material_family_id'],
                'material_origin_id' => (int) $row['material_origin_id'],
                'percentage' => (float) $row['percentage'],
                'average_quality' => $row['average_quality'] ?? null,
            ], $fallback));
        }

        return $fallback;
    }

    private function buildFallback(array $item): array
    {
        $familyId = $this->familyIdFromItem($item);
        $originId = $this->originIdFromItem($item);
        if ($familyId <= 0 || $originId <= 0) {
            return [];
        }

        return [[
            'material_family_id' => $familyId,
            'material_origin_id' => $originId,
            'percentage' => 100.0,
            'average_quality' => $item['quality_value'] ?? null,
            'family_code' => (string) ($item['material_family_code'] ?? $item['definition']['material_family_code'] ?? ''),
            'family_name' => (string) ($item['material_family_name'] ?? ''),
            'origin_code' => (string) ($item['material_origin_code'] ?? ''),
            'origin_name' => (string) ($item['material_origin_name'] ?? ''),
            'stash_tab' => (new MaterialStashTabResolver())->tabForFamilyCode((string) ($item['material_family_code'] ?? '')),
        ]];
    }

    private function familyIdFromItem(array $item): int
    {
        if (!empty($item['definition_material_family_id'])) {
            return (int) $item['definition_material_family_id'];
        }

        $code = (string) ($item['material_family_code'] ?? $item['definition']['material_family_code'] ?? '');
        if ($code === '') {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT id FROM material_families WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function originIdFromItem(array $item): int
    {
        if (!empty($item['material_origin_id'])) {
            return (int) $item['material_origin_id'];
        }

        $defaultCode = (string) Config::get('materials.dismantle.default_origin_code', 'starter_forest');
        $stmt = $this->pdo()->prepare('SELECT id FROM material_origins WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $defaultCode]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function enrichRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $resolver = new MaterialStashTabResolver();
        $enriched = [];

        foreach ($rows as $row) {
            $family = $this->lookupFamily((int) $row['material_family_id']);
            $origin = $this->lookupOrigin((int) $row['material_origin_id']);
            $familyCode = (string) ($family['code'] ?? '');

            $enriched[] = [
                'material_family_id' => (int) $row['material_family_id'],
                'material_origin_id' => (int) $row['material_origin_id'],
                'percentage' => (float) $row['percentage'],
                'average_quality' => $row['average_quality'] !== null ? (float) $row['average_quality'] : null,
                'family_code' => $familyCode,
                'family_name' => (string) ($family['name'] ?? $familyCode),
                'origin_code' => (string) ($origin['code'] ?? ''),
                'origin_name' => (string) ($origin['name'] ?? ''),
                'stash_tab' => (string) ($family['stash_tab'] ?? $resolver->tabForFamilyCode($familyCode)),
            ];
        }

        return $enriched;
    }

    private function lookupFamily(int $familyId): array
    {
        $stmt = $this->pdo()->prepare('SELECT code, name, stash_tab FROM material_families WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function lookupOrigin(int $originId): array
    {
        $stmt = $this->pdo()->prepare('SELECT code, name FROM material_origins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $originId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
