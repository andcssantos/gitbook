<?php

namespace Tests\Game\Identity;

use App\Game\Identity\Repositories\AccountRepository;
use App\Game\Identity\Services\AuthService;
use App\Game\Player\Repositories\PlayerRepository;
use App\Game\Player\Services\PlayerResolver;
use App\Http\HttpException;
use PHPUnit\Framework\TestCase;
use PDO;

class AuthServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);

        $this->pdo->prepare('INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES (:public_id, :display_name, :email, :password_hash, :status)')
            ->execute([
                'public_id' => 'account-public',
                'display_name' => 'Tester',
                'email' => 'tester@example.com',
                'password_hash' => password_hash('correct-password', PASSWORD_ARGON2ID),
                'status' => 'active',
            ]);

        $this->pdo->prepare('INSERT INTO players (public_id, account_id, name, status) VALUES (:public_id, :account_id, :name, :status)')
            ->execute([
                'public_id' => 'player-public',
                'account_id' => 1,
                'name' => 'TesterHero',
                'status' => 'active',
            ]);
    }

    public function testLoginReturnsPublicIdentityAndStoresSessionIdentity(): void
    {
        $identity = $this->service()->login('tester@example.com', 'correct-password');

        $this->assertSame('account-public', $identity['account']['public_id']);
        $this->assertSame('player-public', $identity['player']['public_id']);
        $this->assertSame(1, $_SESSION['user']['account_id']);
        $this->assertSame(1, $_SESSION['user']['player_id']);
    }

    public function testLoginRejectsInvalidPassword(): void
    {
        $this->expectException(HttpException::class);

        $this->service()->login('tester@example.com', 'wrong-password');
    }

    public function testPlayerResolverLoadsAuthenticatedPlayer(): void
    {
        $this->service()->login('tester@example.com', 'correct-password');

        $player = (new PlayerResolver(new PlayerRepository($this->pdo)))->requireCurrentPlayer();

        $this->assertSame('TesterHero', $player['name']);
    }

    private function service(): AuthService
    {
        return new AuthService(
            new AccountRepository($this->pdo),
            new PlayerRepository($this->pdo)
        );
    }
}
