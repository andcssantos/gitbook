<?php

namespace App\Game\Enhancement\Services;

use App\Game\Enhancement\Support\ChanceRoller;
use App\Game\Enhancement\Support\RandomChanceRoller;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;

class SoulJewelService
{
    public function __construct(
        private ?ItemInstanceAffixRepository $affixes = null,
        private ?JewelCompatibilityService $compatibility = null,
        private ?PropertyScopeService $scopes = null,
        private ?UpgradeSuccessCalculator $calculator = null,
        private ?ChanceRoller $roller = null,
        private ?JewelRollProfileService $rollProfiles = null
    ) {
        $this->affixes ??= new ItemInstanceAffixRepository();
        $this->compatibility ??= new JewelCompatibilityService();
        $this->scopes ??= new PropertyScopeService();
        $this->calculator ??= new UpgradeSuccessCalculator();
        $this->roller ??= new RandomChanceRoller();
        $this->rollProfiles ??= new JewelRollProfileService();
    }

    public function apply(int $playerId, array $jewel, array $target): array
    {
        $preview = $this->compatibility->preview($jewel, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            return [
                'action' => 'soul',
                'success' => false,
                'consumed_jewel' => true,
                'reason_code' => $preview['reason_code'] ?? 'ENHANCEMENT_INCOMPATIBLE',
                'reason_message' => $preview['reason_message'] ?? 'Incompatible target.',
            ];
        }

        $successRate = (float) $preview['success_rate'];
        $roll = $this->roller->rollPercent();
        $success = $roll <= $successRate;

        if (!$success) {
            return [
                'action' => 'soul',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'success_rate' => $successRate,
                'changed_affixes' => [],
                'created_affix' => null,
            ];
        }

        $newAffixRoll = $this->roller->rollPercent();
        if ($newAffixRoll <= $this->calculator->soulNewAffixChance()) {
            $created = $this->createRandomAffix((int) $target['id'], (string) $target['category_code'], $this->compatibility->currentUpgradeLevel((int) $target['id']));
            if ($created !== null) {
                return [
                    'action' => 'soul',
                    'success' => true,
                    'consumed_jewel' => true,
                    'roll' => $roll,
                    'success_rate' => $successRate,
                    'changed_affixes' => [],
                    'created_affix' => $created,
                ];
            }
        }

        $changed = $this->upgradeRandomAffix($jewel, (int) $target['id']);

        return [
            'action' => 'soul',
            'success' => true,
            'consumed_jewel' => true,
            'roll' => $roll,
            'success_rate' => $successRate,
            'changed_affixes' => $changed,
            'created_affix' => null,
        ];
    }

    private function upgradeRandomAffix(array $jewel, int $itemInstanceId): array
    {
        $affixes = $this->affixes->listForItem($itemInstanceId);
        if ($affixes === []) {
            return [];
        }

        $picked = $affixes[array_rand($affixes)];
        $current = (float) ($picked['rolled_value'] ?? 0);
        $min = (float) ($picked['min_value'] ?? 0);
        $max = (float) ($picked['max_value'] ?? $current);
        $range = max(1.0, $max - $min);
        $boostPercent = $this->rollProfiles->rollSoulAffixBoostPercent($jewel) / 100;
        $delta = $range * $boostPercent;
        $next = min($max, round($current + $delta, 2));

        $this->affixes->updateRolledValue((int) $picked['id'], $next);

        return [[
            'code' => (string) $picked['code'],
            'property_code' => (string) $picked['property_code'],
            'from' => $current,
            'to' => $next,
        ]];
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

        $this->affixes->insert($itemInstanceId, (int) $picked['id'], $rolled, 'soul_jewel');

        return [
            'code' => (string) $picked['code'],
            'property_code' => (string) $picked['property_code'],
            'value' => $rolled,
        ];
    }
}
