<?php

namespace App\Game\Items\Services;

use App\Game\Items\Repositories\ItemInstancePropertyRepository;

class RarityBaseStatScalingService
{
    private const SCALABLE_SOURCES = ['base', 'definition'];

    public function __construct(
        private ?RarityTierService $rarities = null,
        private ?ItemInstancePropertyRepository $properties = null,
        private ?ItemStatRangeService $statRanges = null
    ) {
        $this->rarities ??= new RarityTierService();
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->statRanges ??= new ItemStatRangeService($this->rarities);
    }

    public function multiplierFor(string $qualityBucket): float
    {
        return $this->statRanges->rarityMultiplier($qualityBucket);
    }

    public function applyUpgradeScaling(int $itemInstanceId, string $fromBucket, string $toBucket, ?array $itemContext = null): array
    {
        if ($itemContext !== null) {
            return $this->applyProportionalUpgradeScaling($itemInstanceId, $fromBucket, $toBucket, $itemContext);
        }

        return $this->applyLegacyMultiplierScaling($itemInstanceId, $fromBucket, $toBucket);
    }

    private function applyProportionalUpgradeScaling(int $itemInstanceId, string $fromBucket, string $toBucket, array $itemContext): array
    {
        $scaled = [];

        foreach ($this->properties->listForItem($itemInstanceId) as $property) {
            $source = (string) ($property['source'] ?? '');
            if (!in_array($source, self::SCALABLE_SOURCES, true)) {
                continue;
            }

            $code = (string) ($property['code'] ?? '');
            if (!in_array($code, ItemStatRangeService::BLESS_STATS, true)) {
                continue;
            }

            $current = $this->readPropertyValue($property);
            if ($current <= 0) {
                continue;
            }

            $next = $this->statRanges->scaleStatForRarityUpgrade($current, $itemContext, $code, $fromBucket, $toBucket);

            $this->properties->upsertNumeric(
                $itemInstanceId,
                (int) $property['property_definition_id'],
                $next,
                $source
            );

            $scaled[] = [
                'code' => $code,
                'name' => (string) $property['name'],
                'from' => $current,
                'to' => $next,
            ];
        }

        return $scaled;
    }

    private function applyLegacyMultiplierScaling(int $itemInstanceId, string $fromBucket, string $toBucket): array
    {
        $fromMultiplier = $this->multiplierFor($fromBucket);
        $toMultiplier = $this->multiplierFor($toBucket);
        if ($toMultiplier <= $fromMultiplier) {
            return [];
        }

        $ratio = $toMultiplier / $fromMultiplier;
        $scaled = [];

        foreach ($this->properties->listForItem($itemInstanceId) as $property) {
            $source = (string) ($property['source'] ?? '');
            if (!in_array($source, self::SCALABLE_SOURCES, true)) {
                continue;
            }

            $valueType = (string) ($property['value_type'] ?? 'numeric');
            $current = $valueType === 'integer'
                ? (float) ($property['integer_value'] ?? 0)
                : (float) ($property['numeric_value'] ?? 0);

            if ($current <= 0) {
                continue;
            }

            $next = $valueType === 'integer'
                ? (float) max(1, (int) round($current * $ratio))
                : round($current * $ratio, 2);

            $this->properties->upsertNumeric(
                $itemInstanceId,
                (int) $property['property_definition_id'],
                $next,
                $source
            );

            $scaled[] = [
                'code' => (string) $property['code'],
                'name' => (string) $property['name'],
                'from' => $current,
                'to' => $next,
            ];
        }

        return $scaled;
    }

    private function readPropertyValue(array $property): int
    {
        if (($property['value_type'] ?? '') === 'integer') {
            return (int) ($property['integer_value'] ?? 0);
        }

        return (int) round((float) ($property['numeric_value'] ?? 0));
    }
}
