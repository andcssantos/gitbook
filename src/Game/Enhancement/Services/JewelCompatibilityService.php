<?php

namespace App\Game\Enhancement\Services;

use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Services\RarityTierService;

class JewelCompatibilityService
{
    private const BLESS_JEWELS = ['jewel_blessing_minor'];
    private const SOUL_JEWELS = ['jewel_soul_minor'];
    private const CHAOS_JEWELS = ['jewel_chaos_minor'];
    private const REROLL_JEWELS = ['jewel_reroll_minor'];
    private const BLESS_BASE_PROPERTIES = [
        'strength',
        'attack_power',
        'defense',
        'armor',
        'agility',
        'vitality',
        'max_health',
        'energy',
    ];

    public function __construct(
        private ?ItemInstancePropertyRepository $properties = null,
        private ?ItemInstanceAffixRepository $affixes = null,
        private ?PropertyScopeService $scopes = null,
        private ?UpgradeSuccessCalculator $calculator = null,
        private ?RarityTierService $rarities = null,
        private ?JewelRollProfileService $rollProfiles = null,
        private ?ItemEnhancementBonusService $bonuses = null
    ) {
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->affixes ??= new ItemInstanceAffixRepository();
        $this->scopes ??= new PropertyScopeService();
        $this->calculator ??= new UpgradeSuccessCalculator();
        $this->rarities ??= new RarityTierService();
        $this->rollProfiles ??= new JewelRollProfileService();
        $this->bonuses ??= new ItemEnhancementBonusService($this->properties, $this->affixes);
    }

    public function resolveJewelType(array $jewel): string
    {
        $code = (string) ($jewel['definition_code'] ?? '');

        if (in_array($code, self::BLESS_JEWELS, true)) {
            return 'bless';
        }

        if (in_array($code, self::SOUL_JEWELS, true)) {
            return 'soul';
        }

        if (in_array($code, self::CHAOS_JEWELS, true)) {
            return 'chaos';
        }

        if (in_array($code, self::REROLL_JEWELS, true)) {
            return 'reroll';
        }

        return 'unknown';
    }

    public function preview(array $jewel, array $target): array
    {
        $type = $this->resolveJewelType($jewel);
        $this->assertJewelItem($jewel);
        $this->assertNotSameItem($jewel, $target);

        return match ($type) {
            'bless' => $this->previewBless($jewel, $target),
            'soul' => $this->previewSoul($jewel, $target),
            'chaos' => $this->previewChaos($jewel, $target),
            'reroll' => $this->previewReroll($jewel, $target),
            default => throw new InventoryException('ENHANCEMENT_JEWEL_UNSUPPORTED', 'This jewel cannot enhance items.', 422),
        };
    }

    public function assertCanApply(array $jewel, array $target): string
    {
        $preview = $this->preview($jewel, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            throw new InventoryException(
                (string) ($preview['reason_code'] ?? 'ENHANCEMENT_INCOMPATIBLE'),
                (string) ($preview['reason_message'] ?? 'Jewel cannot be applied to this item.'),
                422
            );
        }

        return (string) $preview['jewel_type'];
    }

    private function previewBless(array $jewel, array $target): array
    {
        if (!$this->isEnhanceableEquipment($target)) {
            return $this->reject('ENHANCEMENT_TARGET_NOT_EQUIPMENT', 'A joia da bencao so pode ser usada em equipamentos.');
        }

        $currentLevel = $this->currentUpgradeLevel((int) $target['id']);
        if ($currentLevel >= UpgradeSuccessCalculator::MAX_BLESS_LEVEL) {
            return $this->reject('ENHANCEMENT_MAX_LEVEL', 'Este item ja atingiu o nivel maximo de melhoria (+25).');
        }

        $eligible = $this->eligibleBlessProperties((int) $target['id'], (string) $target['category_code']);
        if ($eligible === []) {
            return $this->reject('ENHANCEMENT_NO_ELIGIBLE_STATS', 'Nenhum atributo basico elegivel foi encontrado neste item.');
        }

        $baseRate = $this->jewelBaseSuccessRate($jewel);
        $itemBonus = $this->bonuses->blessSuccessBonusPercent((int) $target['id']);
        $breakdown = $this->calculator->blessSuccessBreakdown($baseRate, $currentLevel, $itemBonus);

        return [
            'can_apply' => true,
            'jewel_type' => 'bless',
            'jewel_code' => (string) $jewel['definition_code'],
            'target_public_id' => (string) $target['public_id'],
            'success_rate' => $breakdown['final_rate'],
            'success_rate_breakdown' => $breakdown,
            'current_upgrade_level' => $currentLevel,
            'max_upgrade_level' => UpgradeSuccessCalculator::MAX_BLESS_LEVEL,
            'eligible_properties' => array_column($eligible, 'code'),
            'property_boost_range' => $this->rollProfiles->blessBoostPercent($jewel),
            'consumes_jewel' => true,
        ];
    }

    private function previewReroll(array $jewel, array $target): array
    {
        if (!$this->isEnhanceableEquipment($target)) {
            return $this->reject('ENHANCEMENT_TARGET_NOT_EQUIPMENT', 'A joia de rerrolagem so pode ser usada em equipamentos.');
        }

        $affixCount = $this->affixes->countForItem((int) $target['id']);
        if ($affixCount <= 0) {
            return $this->reject('ENHANCEMENT_NO_AFFIXES', 'A joia de rerrolagem exige um item com atributos (affixes).');
        }

        $baseRate = $this->jewelBaseSuccessRate($jewel);

        return [
            'can_apply' => true,
            'jewel_type' => 'reroll',
            'jewel_code' => (string) $jewel['definition_code'],
            'target_public_id' => (string) $target['public_id'],
            'success_rate' => $this->calculator->soulSuccessRate($baseRate),
            'affix_count' => $affixCount,
            'consumes_jewel' => true,
        ];
    }

    private function previewChaos(array $jewel, array $target): array
    {
        if (!$this->isEnhanceableEquipment($target)) {
            return $this->reject('ENHANCEMENT_TARGET_NOT_EQUIPMENT', 'A joia do caos so pode ser usada em equipamentos.');
        }

        $currentBucket = $this->rarities->normalize((string) ($target['quality_bucket'] ?? 'common'));
        if ($this->rarities->isChaosCap($currentBucket)) {
            return $this->reject('ENHANCEMENT_CHAOS_MAX_RARITY', 'Itens lendarios, epicos ou divinos nao podem ser alterados pela joia do caos.');
        }

        $chaos = new ChaosJewelService(properties: $this->properties, affixes: $this->affixes);
        $outcomes = $chaos->outcomeChancesForBucket($currentBucket);
        if ($outcomes === []) {
            return $this->reject('ENHANCEMENT_CHAOS_UNSUPPORTED', 'Este item nao pode ser transformado pela joia do caos.');
        }

        return [
            'can_apply' => true,
            'jewel_type' => 'chaos',
            'jewel_code' => (string) $jewel['definition_code'],
            'target_public_id' => (string) $target['public_id'],
            'success_rate' => $chaos->successRateForBucket($currentBucket),
            'current_quality_bucket' => $currentBucket,
            'outcome_chances' => $outcomes,
            'consumes_jewel' => true,
        ];
    }

    private function previewSoul(array $jewel, array $target): array
    {
        if (!$this->isEnhanceableEquipment($target)) {
            return $this->reject('ENHANCEMENT_TARGET_NOT_EQUIPMENT', 'A joia da alma so pode ser usada em equipamentos.');
        }

        $affixCount = $this->affixes->countForItem((int) $target['id']);
        if ($affixCount <= 0) {
            return $this->reject('ENHANCEMENT_NO_AFFIXES', 'A joia da alma exige um item com atributos (affixes).');
        }

        $baseRate = $this->jewelBaseSuccessRate($jewel);

        return [
            'can_apply' => true,
            'jewel_type' => 'soul',
            'jewel_code' => (string) $jewel['definition_code'],
            'target_public_id' => (string) $target['public_id'],
            'success_rate' => $this->calculator->soulSuccessRate($baseRate),
            'new_affix_chance' => $this->calculator->soulNewAffixChance(),
            'affix_count' => $affixCount,
            'affix_boost_range' => $this->rollProfiles->soulAffixBoostPercent($jewel),
            'consumes_jewel' => true,
        ];
    }

    public function eligibleBlessProperties(int $itemInstanceId, string $categoryCode): array
    {
        $rows = $this->properties->listForItem($itemInstanceId);
        $eligible = [];

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if (!in_array($code, self::BLESS_BASE_PROPERTIES, true)) {
                continue;
            }

            $scope = (string) ($row['equipment_scope'] ?? $this->scopes->defaultScope());
            if (!$this->scopes->isAllowedForCategory($scope, $categoryCode)) {
                continue;
            }

            $eligible[] = $row;
        }

        if ($eligible !== []) {
            return $eligible;
        }

        foreach (self::BLESS_BASE_PROPERTIES as $code) {
            $definition = $this->properties->findDefinitionByCode($code);
            if ($definition === null) {
                continue;
            }

            if (!$this->scopes->isAllowedForCategory((string) ($definition['equipment_scope'] ?? 'shared'), $categoryCode)) {
                continue;
            }

            $existing = $this->properties->findByItemAndCode($itemInstanceId, $code);
            $eligible[] = $existing ?? [
                'code' => $code,
                'property_definition_id' => (int) $definition['id'],
                'integer_value' => 0,
                'numeric_value' => 0,
                'value_type' => (string) $definition['value_type'],
                'equipment_scope' => (string) ($definition['equipment_scope'] ?? 'shared'),
            ];
        }

        return $eligible;
    }

    public function currentUpgradeLevel(int $itemInstanceId): int
    {
        $row = $this->properties->findByItemAndCode($itemInstanceId, 'upgrade_level');

        if ($row === null) {
            return 0;
        }

        if (($row['value_type'] ?? '') === 'integer') {
            return max(0, (int) ($row['integer_value'] ?? 0));
        }

        return max(0, (int) round((float) ($row['numeric_value'] ?? 0)));
    }

    public function jewelBaseSuccessRate(array $jewel): float
    {
        $property = $this->properties->findByItemAndCode((int) $jewel['id'], 'upgrade_success_rate');
        if ($property !== null) {
            return (float) ($property['numeric_value'] ?? $property['integer_value'] ?? 0);
        }

        $config = $this->parseBaseConfig($jewel);

        return (float) ($config['upgrade_success_rate'] ?? 0);
    }

    private function isEnhanceableEquipment(array $item): bool
    {
        if (empty($item['equip_slot_code'])) {
            return false;
        }

        return in_array((string) ($item['category_code'] ?? ''), ['weapon', 'armor', 'tool'], true);
    }

    private function assertJewelItem(array $jewel): void
    {
        if ($this->resolveJewelType($jewel) === 'unknown') {
            throw new InventoryException('ENHANCEMENT_NOT_A_JEWEL', 'Selected item is not an enhancement jewel.', 422);
        }

        if ((int) ($jewel['quantity'] ?? 1) !== 1) {
            throw new InventoryException('ENHANCEMENT_JEWEL_NOT_INSTANCE', 'Jewels must be unique item instances.', 422);
        }
    }

    private function assertNotSameItem(array $jewel, array $target): void
    {
        if ((int) $jewel['id'] === (int) $target['id']) {
            throw new InventoryException('ENHANCEMENT_SAME_ITEM', 'A jewel cannot be applied to itself.', 422);
        }
    }

    private function parseBaseConfig(array $item): array
    {
        $raw = $item['base_config'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function reject(string $code, string $message): array
    {
        return [
            'can_apply' => false,
            'reason_code' => $code,
            'reason_message' => $message,
        ];
    }
}
