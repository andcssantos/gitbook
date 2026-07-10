<?php

namespace Tests\Game\Enhancement;

use App\Game\Enhancement\DTO\ApplyJewelRequest;
use App\Game\Enhancement\Repositories\ItemUpgradeEventRepository;
use App\Game\Enhancement\Services\BlessJewelService;
use App\Game\Enhancement\Services\ChaosJewelService;
use App\Game\Enhancement\Services\RerollJewelService;
use App\Game\Enhancement\Services\JewelCompatibilityService;
use App\Game\Enhancement\Services\JewelEnhancementService;
use App\Game\Enhancement\Services\SoulJewelService;
use App\Game\Enhancement\Support\FixedChanceRoller;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class JewelEnhancementTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $foundation = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $foundation->up($this->pdo);

        $actions = require __DIR__ . '/../../../database/migrations/2026_07_09_000006_create_item_action_tables.php';
        $actions->up($this->pdo);

        $progression = require __DIR__ . '/../../../database/migrations/2026_07_09_000007_create_item_progression_tables.php';
        $progression->up($this->pdo);

        $enhancementProps = require __DIR__ . '/../../../database/migrations/2026_07_09_000013_seed_enhancement_property_definitions.php';
        $enhancementProps->up($this->pdo);

        $scopes = require __DIR__ . '/../../../database/migrations/2026_07_09_000014_add_enhancement_property_scopes.php';
        $scopes->up($this->pdo);

        $earringSlot = require __DIR__ . '/../../../database/migrations/2026_07_09_000015_add_earring_equipment_slot.php';
        $earringSlot->up($this->pdo);

        $affixCatalog = require __DIR__ . '/../../../database/migrations/2026_07_10_000016_expand_affix_catalog.php';
        $affixCatalog->up($this->pdo);

        $statRangeFoundation = require __DIR__ . '/../../../database/migrations/2026_07_10_000017_item_stat_range_foundation.php';
        $statRangeFoundation->up($this->pdo);

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1, false);
    }

    public function testBlessPreviewAllowsWeaponTarget(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-1', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-1');

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertTrue($preview['can_apply']);
        $this->assertSame('bless', $preview['jewel_type']);
        $this->assertSame(85.0, $preview['success_rate']);
    }

    public function testBlessSuccessUpgradesLevelAndConsumesJewel(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-bless', 'weapon', 'weapon');
        $this->seedBaseProperty((int) $weapon['id'], 'attack_power', 10);
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-success');

        $service = new JewelEnhancementService(
            $this->pdo,
            bless: new BlessJewelService(
                $this->properties(),
                $this->compatibility(),
                events: new ItemUpgradeEventRepository($this->pdo),
                roller: new FixedChanceRoller(10.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['to_level']);
        $this->assertNull((new ItemInstanceRepository($this->pdo))->findByPublicId((string) $jewel['public_id']));
        $this->assertSame(1, $this->propertyValue((int) $weapon['id'], 'upgrade_level'));
    }

    public function testBlessFailureConsumesJewelWithoutChangingTarget(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-fail', 'weapon', 'weapon');
        $this->seedBaseProperty((int) $weapon['id'], 'attack_power', 10);
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-fail');

        $service = new JewelEnhancementService(
            $this->pdo,
            bless: new BlessJewelService(
                $this->properties(),
                $this->compatibility(),
                events: new ItemUpgradeEventRepository($this->pdo),
                roller: new FixedChanceRoller(99.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertFalse($result['success']);
        $this->assertNull((new ItemInstanceRepository($this->pdo))->findByPublicId((string) $jewel['public_id']));
        $this->assertNull($this->propertyValue((int) $weapon['id'], 'upgrade_level'));
    }

    public function testSoulRequiresAffixes(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-no-affix', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_soul_minor', 'soul-1');

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertFalse($preview['can_apply']);
        $this->assertSame('ENHANCEMENT_NO_AFFIXES', $preview['reason_code']);
    }

    public function testSoulUpgradesExistingAffix(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-soul', 'weapon', 'weapon');
        $this->seedAffix((int) $weapon['id'], 'sharp', 8.0);
        $jewel = $this->createJewel('jewel_soul_minor', 'soul-success');

        $service = new JewelEnhancementService(
            $this->pdo,
            soul: new SoulJewelService(
                $this->affixes(),
                $this->compatibility(),
                roller: new FixedChanceRoller(10.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['changed_affixes']);
        $this->assertGreaterThan(8.0, (float) $result['changed_affixes'][0]['to']);
    }

    public function testDefenseAffixCannotBePreviewedForWeaponThroughScope(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-scope', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_soul_minor', 'soul-scope');
        $this->seedAffix((int) $weapon['id'], 'guarded', 10.0);

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertTrue($preview['can_apply']);
    }

    public function testEnhanceRequiresConfirmation(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-confirm', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-confirm');

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Enhancement requires confirmation.');

        (new JewelEnhancementService($this->pdo))->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            false
        ));
    }

    public function testBlessPreviewAllowsSingleQuantityJewelEvenWhenDefinitionMarkedStackable(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-stack-flag', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-stack-flag');

        $this->pdo->prepare('UPDATE item_definitions SET stackable = 1 WHERE code = :code')
            ->execute(['code' => 'jewel_blessing_minor']);

        $preview = $this->compatibility()->preview(
            (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner('bless-stack-flag', 1),
            (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner('weapon-stack-flag', 1)
        );

        $this->assertTrue($preview['can_apply']);
    }

    public function testSoulFallsBackToAffixUpgradeWhenNoNewAffixIsAvailable(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-soul-fallback', 'weapon', 'weapon');
        foreach ($this->pdo->query('SELECT code FROM item_affix_definitions')->fetchAll(PDO::FETCH_COLUMN) as $affixCode) {
            $this->seedAffix((int) $weapon['id'], (string) $affixCode, 5.0);
        }
        $jewel = $this->createJewel('jewel_soul_minor', 'soul-fallback');

        $service = new JewelEnhancementService(
            $this->pdo,
            soul: new SoulJewelService(
                $this->affixes(),
                $this->compatibility(),
                roller: new FixedChanceRoller([10.0, 0.5])
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertNull($result['created_affix']);
        $this->assertNotEmpty($result['changed_affixes']);
    }

    public function testChaosPreviewOnCommonItem(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-preview', 'weapon', 'weapon', 'common');
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-preview');

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertTrue($preview['can_apply']);
        $this->assertSame('chaos', $preview['jewel_type']);
        $this->assertSame('common', $preview['current_quality_bucket']);
        $this->assertSame(95.0, $preview['success_rate']);
    }

    public function testChaosUpgradesCommonToRare(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-rare', 'weapon', 'weapon', 'common');
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-rare');

        $service = new JewelEnhancementService(
            $this->pdo,
            chaos: new ChaosJewelService(
                new ItemInstanceRepository($this->pdo),
                $this->properties(),
                $this->affixes(),
                new ItemInstanceSocketRepository($this->pdo),
                $this->compatibility(),
                roller: new FixedChanceRoller(60.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('common', $result['from_quality_bucket']);
        $this->assertSame('rare', $result['to_quality_bucket']);
        $this->assertNotEmpty($result['created_affixes']);
        $this->assertSame('rare', (new ItemInstanceRepository($this->pdo))->findByPublicId((string) $weapon['public_id'])['quality_bucket']);
    }

    public function testChaosScalesBaseStatsOnRarityUpgrade(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-scale', 'weapon', 'weapon', 'common');
        $propertyId = (new ItemInstancePropertyRepository($this->pdo))->propertyDefinitionId('attack_power');
        (new ItemInstancePropertyRepository($this->pdo))->upsertNumeric((int) $weapon['id'], $propertyId, 20, 'base');
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-scale');

        $service = new JewelEnhancementService(
            $this->pdo,
            chaos: new ChaosJewelService(
                new ItemInstanceRepository($this->pdo),
                $this->properties(),
                $this->affixes(),
                new ItemInstanceSocketRepository($this->pdo),
                $this->compatibility(),
                roller: new FixedChanceRoller(60.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['scaled_base_stats']);
        $attack = (new ItemInstancePropertyRepository($this->pdo))->findByItemAndCode((int) $weapon['id'], 'attack_power');
        $this->assertGreaterThan(20.0, (float) ($attack['integer_value'] ?? $attack['numeric_value'] ?? 0));
    }

    public function testChaosFailureConsumesJewelWithoutChangingTarget(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-fail', 'weapon', 'weapon', 'common');
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-fail');

        $service = new JewelEnhancementService(
            $this->pdo,
            chaos: new ChaosJewelService(
                new ItemInstanceRepository($this->pdo),
                $this->properties(),
                $this->affixes(),
                new ItemInstanceSocketRepository($this->pdo),
                $this->compatibility(),
                roller: new FixedChanceRoller(98.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertFalse($result['success']);
        $this->assertNull((new ItemInstanceRepository($this->pdo))->findByPublicId((string) $jewel['public_id']));
        $this->assertSame('common', (new ItemInstanceRepository($this->pdo))->findByPublicId((string) $weapon['public_id'])['quality_bucket']);
    }

    public function testChaosBlockedOnLegendaryItem(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-legendary', 'weapon', 'weapon', 'legendary');
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-legendary');

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertFalse($preview['can_apply']);
        $this->assertSame('ENHANCEMENT_CHAOS_MAX_RARITY', $preview['reason_code']);
    }

    public function testRerollReplacesAffixOnSuccess(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-reroll', 'weapon', 'weapon', 'rare');
        $this->seedAffix((int) $weapon['id'], 'sharp', 8.0);
        $jewel = $this->createJewel('jewel_reroll_minor', 'reroll-success');

        $service = new JewelEnhancementService(
            $this->pdo,
            reroll: new RerollJewelService(
                $this->affixes(),
                $this->compatibility(),
                roller: new FixedChanceRoller(10.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['removed_affix']);
        $this->assertNotNull($result['created_affix']);
    }

    public function testBlessPreviewIncludesSuccessRateBreakdown(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-breakdown', 'weapon', 'weapon');
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-breakdown');

        $preview = $this->compatibility()->preview($jewel, $weapon);

        $this->assertTrue($preview['can_apply']);
        $this->assertArrayHasKey('success_rate_breakdown', $preview);
        $this->assertSame(85.0, $preview['success_rate_breakdown']['base_rate']);
        $this->assertSame(85.0, $preview['success_rate_breakdown']['final_rate']);
    }

    public function testBlessPreviewAppliesMasterworkAffixBonus(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-masterwork', 'weapon', 'weapon');
        $this->seedAffix((int) $weapon['id'], 'masterwork', 10.0);
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-masterwork');

        $preview = $this->compatibility()->preview($jewel, (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner('weapon-masterwork', 1));

        $this->assertTrue($preview['can_apply']);
        $this->assertSame(10.0, $preview['success_rate_breakdown']['item_bonus_percent']);
        $this->assertGreaterThan($preview['success_rate_breakdown']['after_decay'], $preview['success_rate_breakdown']['final_rate']);
    }

    public function testBlessSuccessDoesNotExceedCapForUpgradeLevel(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-cap', 'weapon', 'weapon', 'common');
        $this->pdo->prepare('UPDATE item_instances SET quality_value = :quality_value WHERE public_id = :public_id')
            ->execute(['quality_value' => 40.0, 'public_id' => 'weapon-cap']);
        $this->seedBaseProperty((int) $weapon['id'], 'attack_power', 1);
        $jewel = $this->createJewel('jewel_blessing_minor', 'bless-cap');

        $service = new JewelEnhancementService(
            $this->pdo,
            bless: new BlessJewelService(
                $this->properties(),
                $this->compatibility(),
                events: new ItemUpgradeEventRepository($this->pdo),
                roller: new FixedChanceRoller(10.0)
            )
        );

        for ($attempt = 0; $attempt < 8; $attempt += 1) {
            $freshJewel = $this->createJewel('jewel_blessing_minor', 'bless-cap-' . $attempt);
            $service->apply(new ApplyJewelRequest(
                1,
                (string) $freshJewel['public_id'],
                (string) $weapon['public_id'],
                1,
                1,
                true
            ));
        }

        $attack = $this->propertyValue((int) $weapon['id'], 'attack_power');
        $level = $this->propertyValue((int) $weapon['id'], 'upgrade_level');
        $ranges = new \App\Game\Items\Services\ItemStatRangeService();
        $cap = $ranges->allowedCapAtUpgradeLevel([
            'quality_value' => 40.0,
            'quality_bucket' => 'common',
            'properties' => [],
        ], 'attack_power', (int) $level);

        $this->assertLessThanOrEqual($cap, (int) $attack);
    }

    public function testChaosSuccessUpdatesQualityValueAndScalesBaseStats(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-chaos-scale', 'weapon', 'weapon', 'common');
        $this->pdo->prepare('UPDATE item_instances SET quality_value = :quality_value WHERE public_id = :public_id')
            ->execute(['quality_value' => 40.0, 'public_id' => 'weapon-chaos-scale']);
        $this->seedBaseProperty((int) $weapon['id'], 'attack_power', 8);
        $jewel = $this->createJewel('jewel_chaos_minor', 'chaos-scale');

        $service = new JewelEnhancementService(
            $this->pdo,
            chaos: new ChaosJewelService(
                new ItemInstanceRepository($this->pdo),
                $this->properties(),
                $this->affixes(),
                new ItemInstanceSocketRepository($this->pdo),
                $this->compatibility(),
                roller: new FixedChanceRoller(1.0)
            )
        );

        $result = $service->apply(new ApplyJewelRequest(
            1,
            (string) $jewel['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertNotSame('common', $result['to_quality_bucket']);
        $this->assertGreaterThan(40.0, (float) $result['to_quality_value']);
        $this->assertNotEmpty($result['scaled_base_stats']);
    }

    private function properties(): ItemInstancePropertyRepository
    {
        return new ItemInstancePropertyRepository($this->pdo);
    }

    private function affixes(): ItemInstanceAffixRepository
    {
        return new ItemInstanceAffixRepository($this->pdo);
    }

    private function compatibility(): JewelCompatibilityService
    {
        return new JewelCompatibilityService($this->properties(), $this->affixes());
    }

    private function createPlayer(int $id, string $publicId, string $name): void
    {
        $this->pdo->prepare('INSERT INTO accounts (id, public_id, display_name, email, password_hash, status) VALUES (:id, :public_id, :display_name, :email, :password_hash, :status)')
            ->execute([
                'id' => $id,
                'public_id' => "account-{$id}",
                'display_name' => $name,
                'email' => strtolower($name) . '@example.com',
                'password_hash' => password_hash('secret', PASSWORD_ARGON2ID),
                'status' => 'active',
            ]);
        $this->pdo->prepare('INSERT INTO players (id, public_id, account_id, name, status) VALUES (:id, :public_id, :account_id, :name, :status)')
            ->execute([
                'id' => $id,
                'public_id' => $publicId,
                'account_id' => $id,
                'name' => $name,
                'status' => 'active',
            ]);
    }

    private function createEquipment(string $definitionCode, string $publicId, string $categoryCode, string $slot, string $qualityBucket = 'common'): array
    {
        $definitionId = (int) $this->pdo->query("SELECT id FROM item_definitions WHERE code = " . $this->pdo->quote($definitionCode))->fetchColumn();
        $containerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();

        $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, quality_bucket, state, bind_type) VALUES (:public_id, :item_definition_id, :owner_player_id, 1, :quality_bucket, :state, :bind_type)')
            ->execute([
                'public_id' => $publicId,
                'item_definition_id' => $definitionId,
                'owner_player_id' => 1,
                'quality_bucket' => $qualityBucket,
                'state' => 'available',
                'bind_type' => 'none',
            ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, placement_version) VALUES (:container_instance_id, :item_instance_id, 0, 0, 1, 1, 1)')
            ->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
            ]);

        $items = new ItemInstanceRepository($this->pdo);

        return $items->findByPublicIdAndOwner($publicId, 1);
    }

    private function createJewel(string $definitionCode, string $publicId): array
    {
        $definitionId = (int) $this->pdo->query("SELECT id FROM item_definitions WHERE code = " . $this->pdo->quote($definitionCode))->fetchColumn();
        if (!$definitionId) {
            $categoryId = (int) $this->pdo->query("SELECT id FROM item_categories WHERE code = 'material'")->fetchColumn();
            $familyId = (int) $this->pdo->query("SELECT id FROM material_families WHERE code = 'essence'")->fetchColumn();
            $this->pdo->prepare('INSERT INTO item_definitions (code, name, category_id, material_family_id, stackable, max_stack, grid_w, grid_h, base_config, status) VALUES (:code, :name, :category_id, :material_family_id, 0, 1, 1, 1, :base_config, :status)')
                ->execute([
                    'code' => $definitionCode,
                    'name' => $definitionCode,
                    'category_id' => $categoryId,
                    'material_family_id' => $familyId,
                    'base_config' => json_encode(['upgrade_success_rate' => match ($definitionCode) {
                        'jewel_blessing_minor' => 85,
                        'jewel_chaos_minor' => 38,
                        default => 62,
                    }]),
                    'status' => 'active',
                ]);
            $definitionId = (int) $this->pdo->lastInsertId();
        }

        $containerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();

        $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, state, bind_type) VALUES (:public_id, :item_definition_id, :owner_player_id, 1, :state, :bind_type)')
            ->execute([
                'public_id' => $publicId,
                'item_definition_id' => $definitionId,
                'owner_player_id' => 1,
                'state' => 'available',
                'bind_type' => 'none',
            ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $propertyId = (int) $this->pdo->query("SELECT id FROM item_property_definitions WHERE code = 'upgrade_success_rate'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, numeric_value, source) VALUES (:item_instance_id, :property_definition_id, :numeric_value, :source)')
            ->execute([
                'item_instance_id' => $itemId,
                'property_definition_id' => $propertyId,
                'numeric_value' => match ($definitionCode) {
                    'jewel_blessing_minor' => 85,
                    'jewel_chaos_minor' => 38,
                    'jewel_reroll_minor' => 55,
                    default => 62,
                },
                'source' => 'upgrade_jewel',
            ]);

        $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, placement_version) VALUES (:container_instance_id, :item_instance_id, 1, 0, 1, 1, 1)')
            ->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
            ]);

        return (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner($publicId, 1);
    }

    private function seedBaseProperty(int $itemId, string $code, int $value): void
    {
        $propertyId = (int) $this->pdo->query("SELECT id FROM item_property_definitions WHERE code = " . $this->pdo->quote($code))->fetchColumn();
        $this->pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, integer_value, source) VALUES (:item_instance_id, :property_definition_id, :integer_value, :source)')
            ->execute([
                'item_instance_id' => $itemId,
                'property_definition_id' => $propertyId,
                'integer_value' => $value,
                'source' => 'base',
            ]);
    }

    private function seedAffix(int $itemId, string $affixCode, float $value): void
    {
        $affixId = (int) $this->pdo->query("SELECT id FROM item_affix_definitions WHERE code = " . $this->pdo->quote($affixCode))->fetchColumn();
        $this->pdo->prepare('INSERT INTO item_instance_affixes (item_instance_id, affix_definition_id, rolled_value, source) VALUES (:item_instance_id, :affix_definition_id, :rolled_value, :source)')
            ->execute([
                'item_instance_id' => $itemId,
                'affix_definition_id' => $affixId,
                'rolled_value' => $value,
                'source' => 'test',
            ]);
    }

    private function propertyValue(int $itemId, string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT iip.integer_value, iip.numeric_value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id AND ipd.code = :code
            LIMIT 1');
        $stmt->execute([
            'item_instance_id' => $itemId,
            'code' => $code,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return (int) ($row['integer_value'] ?? round((float) $row['numeric_value']));
    }
}
