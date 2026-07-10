<?php

namespace Tests\Game\Socketing;

use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Socketing\DTO\ApplyGemSocketRequest;
use App\Game\Socketing\Services\GemSocketCompatibilityService;
use App\Game\Socketing\Services\GemSocketService;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class GemSocketTest extends TestCase
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

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1, false);
    }

    public function testSocketPreviewRequiresEmptySocket(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-socket', 'weapon', 'weapon');
        $gem = $this->createGem('gem_ruby_attack', 'gem-1');

        $preview = $this->compatibility()->preview($gem, $weapon);

        $this->assertFalse($preview['can_apply']);
        $this->assertSame('SOCKET_NO_EMPTY_SLOT', $preview['reason_code']);
    }

    public function testSocketPreviewAllowsWeaponWithSockets(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-socket-ok', 'weapon', 'weapon');
        $this->seedSockets((int) $weapon['id'], 2);
        $gem = $this->createGem('gem_ruby_attack', 'gem-ok');

        $preview = $this->compatibility()->preview($gem, $weapon);

        $this->assertTrue($preview['can_apply']);
        $this->assertSame(2, $preview['empty_socket_count']);
        $this->assertSame('attack_power', $preview['gem_effect']['property']);
    }

    public function testSocketApplyFillsSocketAndAppliesBonus(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-apply', 'weapon', 'weapon');
        $this->seedSockets((int) $weapon['id'], 1);
        $gem = $this->createGem('gem_ruby_attack', 'gem-apply');

        $result = (new GemSocketService($this->pdo))->apply(new ApplyGemSocketRequest(
            1,
            (string) $gem['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            true
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['socket_index']);
        $this->assertSame('attack_power', $result['applied_effect']['property']);

        $gemRow = (new ItemInstanceRepository($this->pdo))->findByPublicId((string) $gem['public_id']);
        $this->assertSame('socketed', $gemRow['state']);

        $socket = $this->pdo->query('SELECT status FROM item_instance_sockets WHERE item_instance_id = ' . (int) $weapon['id'])->fetchColumn();
        $this->assertSame('filled', $socket);
    }

    public function testSocketRequiresConfirmation(): void
    {
        $weapon = $this->createEquipment('iron_sword', 'weapon-confirm', 'weapon', 'weapon');
        $this->seedSockets((int) $weapon['id'], 1);
        $gem = $this->createGem('gem_ruby_attack', 'gem-confirm');

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Socketing requires confirmation.');

        (new GemSocketService($this->pdo))->apply(new ApplyGemSocketRequest(
            1,
            (string) $gem['public_id'],
            (string) $weapon['public_id'],
            1,
            1,
            false
        ));
    }

    private function compatibility(): GemSocketCompatibilityService
    {
        return new GemSocketCompatibilityService(
            new ItemInstanceSocketRepository($this->pdo),
            new ItemInstancePropertyRepository($this->pdo)
        );
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

    private function createEquipment(string $definitionCode, string $publicId, string $categoryCode, string $slot): array
    {
        $definitionId = (int) $this->pdo->query("SELECT id FROM item_definitions WHERE code = " . $this->pdo->quote($definitionCode))->fetchColumn();
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

        $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, placement_version) VALUES (:container_instance_id, :item_instance_id, 0, 0, 1, 3, 1)')
            ->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
            ]);

        return (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner($publicId, 1);
    }

    private function createGem(string $definitionCode, string $publicId): array
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
                    'base_config' => json_encode(['gem_effect' => ['property' => 'attack_power', 'value' => 7]]),
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

        $propertyId = (int) $this->pdo->query("SELECT id FROM item_property_definitions WHERE code = 'attack_power'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, integer_value, source) VALUES (:item_instance_id, :property_definition_id, :integer_value, :source)')
            ->execute([
                'item_instance_id' => $itemId,
                'property_definition_id' => $propertyId,
                'integer_value' => 7,
                'source' => 'gem',
            ]);

        $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, placement_version) VALUES (:container_instance_id, :item_instance_id, 1, 0, 1, 1, 1)')
            ->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
            ]);

        return (new ItemInstanceRepository($this->pdo))->findByPublicIdAndOwner($publicId, 1);
    }

    private function seedSockets(int $itemId, int $count): void
    {
        for ($index = 0; $index < $count; $index += 1) {
            $this->pdo->prepare('INSERT INTO item_instance_sockets (item_instance_id, socket_index, socket_type, status) VALUES (:item_instance_id, :socket_index, :socket_type, :status)')
                ->execute([
                    'item_instance_id' => $itemId,
                    'socket_index' => $index,
                    'socket_type' => 'generic',
                    'status' => 'empty',
                ]);
        }
    }
}
