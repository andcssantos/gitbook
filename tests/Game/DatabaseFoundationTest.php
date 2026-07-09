<?php

namespace Tests\Game;

use App\Game\Containers\ContainerPlacementValidator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class DatabaseFoundationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);

        $seed = require __DIR__ . '/../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);
    }

    public function testAccountsAndPlayersTablesExistWithForeignKey(): void
    {
        $this->assertTrue($this->tableExists('accounts'));
        $this->assertTrue($this->tableExists('players'));

        $foreignKeys = $this->foreignKeys('players');

        $this->assertContains('accounts', array_column($foreignKeys, 'table'));
    }

    public function testItemDefinitionsAreSeparatedFromItemInstances(): void
    {
        $this->assertTrue($this->tableExists('item_definitions'));
        $this->assertTrue($this->tableExists('item_instances'));
        $this->assertContains('code', $this->columns('item_definitions'));
        $this->assertContains('public_id', $this->columns('item_instances'));
        $this->assertContains('owner_player_id', $this->columns('item_instances'));
        $this->assertNotContains('owner_player_id', $this->columns('item_definitions'));
    }

    public function testContainerDefinitionsAndInstancesRepresentMainInventoryAndBackpack(): void
    {
        $this->assertTrue($this->tableExists('container_definitions'));
        $this->assertTrue($this->tableExists('container_instances'));

        $this->createPlayerFixture();
        $mainDefinitionId = $this->id('container_definitions', 'main_inventory_level_1');
        $backpackDefinitionId = $this->id('container_definitions', 'small_backpack');
        $backpackItemDefinitionId = $this->id('item_definitions', 'small_leather_backpack');

        $this->pdo->prepare("INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, state) VALUES ('item-backpack-1', :definition_id, 1, 1, 'available')")
            ->execute(['definition_id' => $backpackItemDefinitionId]);

        $this->pdo->prepare("INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, source_item_instance_id, name, grid_columns, grid_rows, status) VALUES ('container-main-1', :definition_id, 1, NULL, 'Main', 8, 5, 'active')")
            ->execute(['definition_id' => $mainDefinitionId]);

        $this->pdo->prepare("INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, source_item_instance_id, name, grid_columns, grid_rows, status) VALUES ('container-backpack-1', :definition_id, 1, 1, 'Backpack', 4, 4, 'active')")
            ->execute(['definition_id' => $backpackDefinitionId]);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM container_instances')->fetchColumn();

        $this->assertSame(2, $count);
    }

    public function testContainerItemPlacementUsesLogicalGridCoordinates(): void
    {
        $this->assertTrue($this->tableExists('container_items'));

        $columns = $this->columns('container_items');

        foreach (['grid_x', 'grid_y', 'grid_w', 'grid_h', 'placement_version'] as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function testContainerAcceptanceRulesAreSeeded(): void
    {
        $this->assertTrue($this->tableExists('container_acceptance_rules'));

        $rules = (int) $this->pdo->query('SELECT COUNT(*) FROM container_acceptance_rules')->fetchColumn();
        $this->assertGreaterThan(0, $rules);

        $mainInventoryId = $this->id('container_definitions', 'main_inventory_level_1');
        $stmt = $this->pdo->prepare('SELECT rule_type FROM container_acceptance_rules WHERE container_definition_id = :container_definition_id ORDER BY priority ASC');
        $stmt->execute(['container_definition_id' => $mainInventoryId]);

        $this->assertContains('ACCEPT_ALL', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'rule_type'));
    }

    public function testContainerAcceptanceRulesDoNotStorePixelCoordinates(): void
    {
        foreach ($this->columns('container_acceptance_rules') as $column) {
            $this->assertStringNotContainsString('pixel', strtolower($column));
            $this->assertStringNotContainsString('px', strtolower($column));
        }
    }

    public function testUniqueItemInstanceCannotBePlacedInTwoContainers(): void
    {
        $this->createPlayerFixture();
        $itemDefinitionId = $this->id('item_definitions', 'iron_sword');
        $mainDefinitionId = $this->id('container_definitions', 'main_inventory_level_1');
        $chestDefinitionId = $this->id('container_definitions', 'wooden_chest');

        $this->pdo->prepare("INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, state) VALUES ('item-sword-1', :definition_id, 1, 1, 'available')")
            ->execute(['definition_id' => $itemDefinitionId]);
        $this->pdo->prepare("INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, name, grid_columns, grid_rows, status) VALUES ('container-main-1', :definition_id, 1, 'Main', 8, 5, 'active')")
            ->execute(['definition_id' => $mainDefinitionId]);
        $this->pdo->prepare("INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, name, grid_columns, grid_rows, status) VALUES ('container-chest-1', :definition_id, 1, 'Chest', 10, 8, 'active')")
            ->execute(['definition_id' => $chestDefinitionId]);

        $this->pdo->exec('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES (1, 1, 0, 0, 1, 3)');

        $this->expectException(PDOException::class);
        $this->pdo->exec('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES (2, 1, 1, 1, 1, 3)');
    }

    public function testBackpackDefinitionIsContainerAndNestingCanBeRejected(): void
    {
        $backpack = $this->fetchByCode('item_definitions', 'small_leather_backpack');
        $smallBackpackContainer = $this->fetchByCode('container_definitions', 'small_backpack');

        $this->assertSame(1, (int) $backpack['is_container']);
        $this->assertFalse((new ContainerPlacementValidator())->canPlaceItemDefinition($smallBackpackContainer, $backpack));
    }

    public function testNoInventoryTableStoresPixelCoordinates(): void
    {
        foreach (['container_definitions', 'container_instances', 'container_items'] as $table) {
            foreach ($this->columns($table) as $column) {
                $this->assertStringNotContainsString('pixel', strtolower($column));
                $this->assertStringNotContainsString('px', strtolower($column));
            }
        }
    }

    private function createPlayerFixture(): void
    {
        $passwordHash = password_hash('secret', PASSWORD_ARGON2ID);
        $this->pdo->prepare("INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES ('account-1', 'Tester', 'tester@example.com', :password_hash, 'active')")
            ->execute(['password_hash' => $passwordHash]);
        $this->pdo->exec("INSERT INTO players (public_id, account_id, name, status) VALUES ('player-1', 1, 'Tester', 'active')");
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function columns(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private function foreignKeys(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA foreign_key_list({$table})");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function id(string $table, string $code): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function fetchByCode(string $table, string $code): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
