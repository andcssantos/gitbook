<?php

namespace App\Game\Enhancement\Services;

use App\Game\Enhancement\Support\ChanceRoller;
use App\Game\Enhancement\Support\RandomChanceRoller;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;

class RerollJewelService
{
    public function __construct(
        private ?ItemInstanceAffixRepository $affixes = null,
        private ?JewelCompatibilityService $compatibility = null,
        private ?PropertyScopeService $scopes = null,
        private ?ChanceRoller $roller = null
    ) {
        $this->affixes ??= new ItemInstanceAffixRepository();
        $this->compatibility ??= new JewelCompatibilityService();
        $this->scopes ??= new PropertyScopeService();
        $this->roller ??= new RandomChanceRoller();
    }

    public function apply(int $playerId, array $jewel, array $target): array
    {
        $preview = $this->compatibility->preview($jewel, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            return [
                'action' => 'reroll',
                'success' => false,
                'consumed_jewel' => true,
                'reason_code' => $preview['reason_code'] ?? 'ENHANCEMENT_INCOMPATIBLE',
                'reason_message' => $preview['reason_message'] ?? 'Incompatible target.',
            ];
        }

        $successRate = (float) $preview['success_rate'];
        $roll = $this->roller->rollPercent();
        if ($roll > $successRate) {
            return [
                'action' => 'reroll',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'success_rate' => $successRate,
                'removed_affix' => null,
                'created_affix' => null,
            ];
        }

        $affixes = $this->affixes->listForItem((int) $target['id']);
        if ($affixes === []) {
            return [
                'action' => 'reroll',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'success_rate' => $successRate,
                'removed_affix' => null,
                'created_affix' => null,
            ];
        }

        $removed = $affixes[array_rand($affixes)];
        $upgradeLevel = $this->compatibility->currentUpgradeLevel((int) $target['id']);
        $created = $this->createReplacementAffix(
            (int) $target['id'],
            (string) ($target['category_code'] ?? ''),
            $upgradeLevel,
            array_map(fn (array $row): string => (string) $row['code'], $affixes)
        );

        if ($created === null) {
            return [
                'action' => 'reroll',
                'success' => false,
                'consumed_jewel' => true,
                'roll' => $roll,
                'success_rate' => $successRate,
                'removed_affix' => null,
                'created_affix' => null,
            ];
        }

        $this->affixes->deleteById((int) $removed['id']);

        return [
            'action' => 'reroll',
            'success' => true,
            'consumed_jewel' => true,
            'roll' => $roll,
            'success_rate' => $successRate,
            'removed_affix' => [
                'code' => (string) $removed['code'],
                'name' => (string) $removed['name'],
                'value' => (float) $removed['rolled_value'],
            ],
            'created_affix' => $created,
        ];
    }

    private function createReplacementAffix(
        int $itemInstanceId,
        string $categoryCode,
        int $upgradeLevel,
        array $blockedCodes
    ): ?array {
        $candidates = [];
        foreach ($this->affixes->listEligibleDefinitionsForCategory($categoryCode, max(1, $upgradeLevel)) as $definition) {
            if (in_array((string) $definition['code'], $blockedCodes, true)) {
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

        $this->affixes->insert($itemInstanceId, (int) $picked['id'], $rolled, 'reroll_jewel');

        return [
            'code' => (string) $picked['code'],
            'name' => (string) $picked['name'],
            'property_code' => (string) $picked['property_code'],
            'value' => $rolled,
        ];
    }
}
