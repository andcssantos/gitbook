<?php

namespace App\Game\Identity\Services;

use App\Game\Identity\Repositories\AccountRepository;
use App\Game\Player\Repositories\PlayerRepository;
use App\Http\HttpException;
use App\Utils\Construct\Auth;

class AuthService
{
    public function __construct(
        private ?AccountRepository $accounts = null,
        private ?PlayerRepository $players = null
    ) {
        $this->accounts ??= new AccountRepository();
        $this->players ??= new PlayerRepository();
    }

    public function login(string $email, string $password): array
    {
        $account = $this->accounts->findActiveByEmail($email);
        if ($account === null || !password_verify($password, (string) $account['password_hash'])) {
            throw new HttpException('Invalid credentials.', 401);
        }

        $player = $this->players->findDefaultActiveByAccountId((int) $account['id']);
        if ($player === null) {
            throw new HttpException('No active player found for this account.', 403);
        }

        $sessionIdentity = [
            'account_id' => (int) $account['id'],
            'account_public_id' => $account['public_id'],
            'display_name' => $account['display_name'],
            'email' => $account['email'],
            'player_id' => (int) $player['id'],
            'player_public_id' => $player['public_id'],
            'player_name' => $player['name'],
        ];

        Auth::login($sessionIdentity);

        return $this->publicIdentity($sessionIdentity);
    }

    public function logout(): void
    {
        Auth::logout();
    }

    public function currentIdentity(): ?array
    {
        $user = Auth::user();

        return is_array($user) ? $this->publicIdentity($user) : null;
    }

    private function publicIdentity(array $identity): array
    {
        return [
            'account' => [
                'public_id' => (string) ($identity['account_public_id'] ?? ''),
                'display_name' => (string) ($identity['display_name'] ?? ''),
                'email' => (string) ($identity['email'] ?? ''),
            ],
            'player' => [
                'public_id' => (string) ($identity['player_public_id'] ?? ''),
                'name' => (string) ($identity['player_name'] ?? ''),
            ],
        ];
    }
}
