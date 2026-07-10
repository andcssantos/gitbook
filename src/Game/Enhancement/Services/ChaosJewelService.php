<?php

namespace App\Game\Enhancement\Services;

use App\Game\Enhancement\Support\ChanceRoller;
use App\Game\Enhancement\Support\RandomChanceRoller;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\RarityBaseStatScalingService;
use App\Game\Items\Services\RarityTierService;
use App\Game\Items\Services\ItemStatRangeService;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;

class ChaosJewelService
{
    public function __construct(
        private ?ItemInstanceRepository $items = null,
        private ?ItemInstancePropertyRepository $properties = null,
        private ?ItemInstanceAffixRepository $affixes = null,
        private ?ItemInstanceSocketRepository $sockets = null,
        private ?JewelCompatibilityService $compatibility = null,
        private ?PropertyScopeService $scopes = null,
        private ?RarityTierService $rarities = null,
        private ?RarityBaseStatScalingService $baseScaling = null,
        private ?ItemStatRangeService $statRanges = null,
        private ?ChanceRoller $roller = null
    ) {
        $this->items ??= new ItemInstanceRepository();
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->affixes ??= new ItemInstanceAffixRepository();
        $this->sockets ??= new ItemInstanceSocketRepository();
        $this->compatibility ??= new JewelCompatibilityService($this->properties, $this->affixes);
        $this->scopes ??= new PropertyScopeService();
        $this->rarities ??= new RarityTierService();
        $this->statRanges ??= new ItemStatRangeService($this->rarities);
        $this->baseScaling ??= new RarityBaseStatScalingService($this->rarities, $this->properties, $this->statRanges);
        $this->roller ??= new RandomChanceRoller();
    }

    public function apply(int $playerId, array $jewel, array $target): array
    {
        $preview = $this->compatibility->preview($jewel, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            return [
                'action' => 'chaos',
                'success' => false,
                'consumed_jewel' => true,
                'reason_code' => $preview['reason_code'] ?? 'ENHANCEMENT_INCOMPATIBLE',
                'reason_message' => $preview['reason_message'] ?? 'Incompatible target.',
            ];
        }

        $currentBucket = (string) $preview['current_quality_bucket'];
        $outcomes = (array) ($preview['outcome_chances'] ?? []);
        $roll = $this->roller->rollPercent();
        $picked = $this->pickOutcome($outcomes, $roll);

        if ($picked === null || $picked === 'failure') {
            return [
                'action' => 'chaos',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'from_quality_bucket' => $currentBucket,
                'to_quality_bucket' => $currentBucket,
                'created_affixes' => [],
            ];
        }

        $targetBucket = (string) $picked;
        if ($this->rarities->index($targetBucket) <= $this->rarities->index($currentBucket)) {
            return [
                'action' => 'chaos',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'from_quality_bucket' => $currentBucket,
                'to_quality_bucket' => $currentBucket,
                'created_affixes' => [],
            ];
        }

        $itemId = (int) $target['id'];
        $categoryCode = (string) ($target['category_code'] ?? '');
        $itemContext = [
            'quality_value' => $target['quality_value'] ?? null,
            'quality_bucket' => $currentBucket,
            'properties' => $this->properties->listForItem($itemId),
        ];
        $newQualityValue = $this->statRanges->suggestedQualityValue($itemContext, $targetBucket);
        $previousQualityValue = $itemContext['quality_value'];
        $this->items->updateQuality($itemId, $targetBucket, $newQualityValue);
        $itemContext['quality_bucket'] = $targetBucket;
        $itemContext['quality_value'] = $newQualityValue;
        $scaledBaseStats = $this->baseScaling->applyUpgradeScaling($itemId, $currentBucket, $targetBucket, $itemContext);
        $this->applyUpgradeLevel($itemId, $targetBucket);
        $createdAffixes = $this->ensureAffixesForTier($itemId, $categoryCode, $targetBucket);
        $this->ensureSocketsForTier($itemId, $categoryCode, $targetBucket);

        return [
            'action' => 'chaos',
            'success' => true,
            'consumed_jewel' => true,
            'roll' => $roll,
            'from_quality_bucket' => $currentBucket,
            'to_quality_bucket' => $targetBucket,
            'from_quality_value' => $previousQualityValue,
            'to_quality_value' => $newQualityValue,
            'scaled_base_stats' => $scaledBaseStats,
            'created_affixes' => $createdAffixes,
        ];
    }

    public function outcomeChancesForBucket(string $qualityBucket): array
    {
        return match ($this->rarities->normalize($qualityBucket)) {
            'common' => [
                ['tier' => 'uncommon', 'chance' => 28.0],
                ['tier' => 'magic', 'chance' => 24.0],
                ['tier' => 'rare', 'chance' => 20.0],
                ['tier' => 'legendary', 'chance' => 12.0],
                ['tier' => 'epic', 'chance' => 8.0],
                ['tier' => 'divine', 'chance' => 3.0],
                ['tier' => 'failure', 'chance' => 5.0],
            ],
            'uncommon' => [
                ['tier' => 'magic', 'chance' => 34.0],
                ['tier' => 'rare', 'chance' => 26.0],
                ['tier' => 'legendary', 'chance' => 14.0],
                ['tier' => 'epic', 'chance' => 8.0],
                ['tier' => 'divine', 'chance' => 3.0],
                ['tier' => 'failure', 'chance' => 15.0],
            ],
            'magic' => [
                ['tier' => 'rare', 'chance' => 36.0],
                ['tier' => 'legendary', 'chance' => 22.0],
                ['tier' => 'epic', 'chance' => 12.0],
                ['tier' => 'divine', 'chance' => 5.0],
                ['tier' => 'failure', 'chance' => 25.0],
            ],
            'rare' => [
                ['tier' => 'legendary', 'chance' => 42.0],
                ['tier' => 'epic', 'chance' => 24.0],
                ['tier' => 'divine', 'chance' => 8.0],
                ['tier' => 'failure', 'chance' => 26.0],
            ],
            default => [],
        };
    }

    public function successRateForBucket(string $qualityBucket): float
    {
        $total = 0.0;
        foreach ($this->outcomeChancesForBucket($qualityBucket) as $entry) {
            if (($entry['tier'] ?? '') === 'failure') {
                continue;
            }

            $total += (float) ($entry['chance'] ?? 0);
        }

        return round($total, 2);
    }

    private function pickOutcome(array $outcomes, float $roll): ?string
    {
        $cursor = 0.0;
        foreach ($outcomes as $entry) {
            $cursor += (float) ($entry['chance'] ?? 0);
            if ($roll <= $cursor) {
                return (string) ($entry['tier'] ?? 'failure');
            }
        }

        return null;
    }

    private function applyUpgradeLevel(int $itemInstanceId, string $qualityBucket): void
    {
        $level = $this->rarities->upgradeLevelFor($qualityBucket);
        if ($level <= 0) {
            return;
        }

        $definitionId = $this->properties->propertyDefinitionId('upgrade_level');
        $this->properties->upsertNumeric($itemInstanceId, $definitionId, $level, 'upgrade');
    }

    private function ensureAffixesForTier(int $itemInstanceId, string $categoryCode, string $toBucket): array
    {
        $targetCount = $this->rarities->affixTargetCount($toBucket);
        if ($targetCount <= 0) {
            return [];
        }

        $created = [];
        $upgradeLevel = max(1, $this->rarities->upgradeLevelFor($toBucket));

        while ($this->affixes->countForItem($itemInstanceId) < $targetCount) {
            $affix = $this->createRandomAffix($itemInstanceId, $categoryCode, $upgradeLevel);
            if ($affix === null) {
                break;
            }

            $created[] = $affix;
        }

        return $created;
    }

    private function createRandomAffix(int $itemInstanceId, string $categoryCode, int $upgradeLevel): ?array
    {
        $existingCodes = array_map(
            fn (array $row): string => (string) $row['code'],
            $this->affixes->listForItem($itemInstanceId)
        );

        $candidates = [];
        foreach ($this->affixes->listEligibleDefinitionsForCategory($categoryCode, $upgradeLevel) as $definition) {
            if (in_array((string) $definition['code'], $existingCodes, true)) {
                continue;
            }

            if (!$this->scopes->isAllowedForCategory((string) ($definition['equipment_scope'] ?? 'shared'), $categoryCode)) {
                continue;
            }

            $candidates[] = $definition;
        }

        if ($candidates === []) {
            return null;
        }

        $picked = $candidates[array_rand($candidates)];
        $min = (float) $picked['min_value'];
        $max = (float) $picked['max_value'];
        $rolled = round($min + (random_int(0, 1000) / 1000) * max(0.0, $max - $min), 2);

        $this->affixes->insert($itemInstanceId, (int) $picked['id'], $rolled, 'chaos_jewel');

        return [
            'code' => (string) $picked['code'],
            'name' => (string) $picked['name'],
            'property_code' => (string) $picked['property_code'],
            'value' => $rolled,
        ];
    }

    private function ensureSocketsForTier(int $itemInstanceId, string $categoryCode, string $qualityBucket): void
    {
        if (!in_array($categoryCode, ['weapon', 'armor', 'tool'], true)) {
            return;
        }

        $required = $this->rarities->socketTargetCount($qualityBucket);
        if ($required <= 0) {
            return;
        }

        $this->sockets->ensureCount($itemInstanceId, $required);
    }
}
