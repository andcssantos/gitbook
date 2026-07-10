<?php

namespace App\Game\Enhancement\Services;

use App\Game\Enhancement\Repositories\ItemUpgradeEventRepository;
use App\Game\Enhancement\Support\ChanceRoller;
use App\Game\Enhancement\Support\RandomChanceRoller;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Services\ItemStatRangeService;

class BlessJewelService
{
    public function __construct(
        private ?ItemInstancePropertyRepository $properties = null,
        private ?JewelCompatibilityService $compatibility = null,
        private ?UpgradeSuccessCalculator $calculator = null,
        private ?ItemUpgradeEventRepository $events = null,
        private ?ChanceRoller $roller = null,
        private ?JewelRollProfileService $rollProfiles = null,
        private ?ItemStatRangeService $statRanges = null
    ) {
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->compatibility ??= new JewelCompatibilityService($this->properties);
        $this->calculator ??= new UpgradeSuccessCalculator();
        $this->events ??= new ItemUpgradeEventRepository();
        $this->roller ??= new RandomChanceRoller();
        $this->rollProfiles ??= new JewelRollProfileService();
        $this->statRanges ??= new ItemStatRangeService();
    }

    public function apply(int $playerId, array $jewel, array $target): array
    {
        $preview = $this->compatibility->preview($jewel, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            return [
                'action' => 'bless',
                'success' => false,
                'consumed_jewel' => true,
                'reason_code' => $preview['reason_code'] ?? 'ENHANCEMENT_INCOMPATIBLE',
                'reason_message' => $preview['reason_message'] ?? 'Incompatible target.',
            ];
        }

        $currentLevel = (int) $preview['current_upgrade_level'];
        $successRate = (float) $preview['success_rate'];
        $roll = $this->roller->rollPercent();
        $success = $roll <= $successRate;

        if (!$success) {
            $this->events->record((int) $target['id'], $playerId, $currentLevel, $currentLevel, false, (string) $jewel['definition_code']);

            return [
                'action' => 'bless',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'success_rate' => $successRate,
                'from_level' => $currentLevel,
                'to_level' => $currentLevel,
                'changed_properties' => [],
            ];
        }

        $nextLevel = $currentLevel + 1;
        $changed = $this->boostRandomBaseStats($jewel, $target, (string) $target['category_code'], $nextLevel);

        $this->events->record((int) $target['id'], $playerId, $currentLevel, $nextLevel, true, (string) $jewel['definition_code']);

        return [
            'action' => 'bless',
            'success' => true,
            'consumed_jewel' => true,
            'roll' => $roll,
            'success_rate' => $successRate,
            'from_level' => $currentLevel,
            'to_level' => $nextLevel,
            'changed_properties' => $changed,
        ];
    }

    private function boostRandomBaseStats(array $jewel, array $target, string $categoryCode, int $nextLevel): array
    {
        $itemInstanceId = (int) $target['id'];
        $eligible = $this->compatibility->eligibleBlessProperties($itemInstanceId, $categoryCode);
        if ($eligible === []) {
            return [];
        }

        shuffle($eligible);
        $pickCount = min(count($eligible), random_int(1, min(3, count($eligible))));
        $picked = array_slice($eligible, 0, $pickCount);
        $changed = [];
        $boostPercent = $this->rollProfiles->rollBlessBoostPercent($jewel) / 100;
        $itemContext = $this->itemContextForRanges($target);

        $upgradeLevelId = $this->properties->propertyDefinitionId('upgrade_level');
        $this->properties->upsertNumeric($itemInstanceId, $upgradeLevelId, $nextLevel, 'upgrade');

        foreach ($picked as $property) {
            $code = (string) $property['code'];
            $definition = $this->properties->findDefinitionByCode($code);
            if ($definition === null) {
                continue;
            }

            $current = $this->readPropertyValue($property);
            $cap = $this->statRanges->allowedCapAtUpgradeLevel($itemContext, $code, $nextLevel);
            if ($current >= $cap) {
                continue;
            }

            $remaining = max(0, $cap - $current);
            $proposedDelta = max(1, (int) round(max(1, $remaining) * $boostPercent));
            $nextValue = $this->statRanges->cappedBlessValue($current, $proposedDelta, $itemContext, $code, $nextLevel);

            if ($nextValue <= $current) {
                continue;
            }

            $this->properties->upsertNumeric(
                $itemInstanceId,
                (int) $definition['id'],
                $nextValue,
                $this->propertySource($property)
            );

            $changed[] = [
                'code' => $code,
                'from' => $current,
                'to' => $nextValue,
                'delta' => $nextValue - $current,
                'cap_at_level' => $cap,
            ];
        }

        return $changed;
    }

    private function itemContextForRanges(array $target): array
    {
        return [
            'quality_value' => $target['quality_value'] ?? null,
            'quality_bucket' => $target['quality_bucket'] ?? 'common',
            'properties' => $this->properties->listForItem((int) $target['id']),
        ];
    }

    private function readPropertyValue(array $property): int
    {
        if (($property['value_type'] ?? '') === 'integer') {
            return (int) ($property['integer_value'] ?? 0);
        }

        return (int) round((float) ($property['numeric_value'] ?? 0));
    }

    private function propertySource(array $property): string
    {
        $source = (string) ($property['source'] ?? '');

        return $source !== '' ? $source : 'base';
    }
}
