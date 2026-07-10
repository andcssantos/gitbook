<?php

namespace App\Game\Market\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

class PlayerCurrencyService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function ensureWallets(int $playerId): void
    {
        foreach (array_keys((array) Config::get('market.currencies', [])) as $currencyCode) {
            $this->ensureWallet($playerId, (string) $currencyCode);
        }
    }

    public function balance(int $playerId, string $currencyCode): int
    {
        $this->ensureWallet($playerId, $currencyCode);
        $stmt = $this->pdo()->prepare('SELECT balance FROM player_currency_wallets WHERE player_id = :player_id AND currency_code = :currency_code LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function credit(int $playerId, string $currencyCode, int $amount, string $reasonCode, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): int
    {
        if ($amount <= 0) {
            return $this->balance($playerId, $currencyCode);
        }

        $this->ensureWallet($playerId, $currencyCode);
        $stmt = $this->pdo()->prepare('UPDATE player_currency_wallets SET balance = balance + :amount WHERE player_id = :player_id AND currency_code = :currency_code');
        $stmt->execute([
            'amount' => $amount,
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        $balanceAfter = $this->balance($playerId, $currencyCode);
        $this->ledger($playerId, $currencyCode, $amount, $balanceAfter, $reasonCode, $referenceType, $referenceId, $metadata);

        return $balanceAfter;
    }

    public function debit(int $playerId, string $currencyCode, int $amount, string $reasonCode, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): int
    {
        if ($amount <= 0) {
            return $this->balance($playerId, $currencyCode);
        }

        $current = $this->balance($playerId, $currencyCode);
        if ($current < $amount) {
            throw new \App\Game\Inventory\InventoryException('MARKET_INSUFFICIENT_FUNDS', 'Saldo insuficiente para esta operacao.', 422);
        }

        $stmt = $this->pdo()->prepare('UPDATE player_currency_wallets SET balance = balance - :amount WHERE player_id = :player_id AND currency_code = :currency_code');
        $stmt->execute([
            'amount' => $amount,
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        $balanceAfter = $this->balance($playerId, $currencyCode);
        $this->ledger($playerId, $currencyCode, -$amount, $balanceAfter, $reasonCode, $referenceType, $referenceId, $metadata);

        return $balanceAfter;
    }

    public function walletsForPlayer(int $playerId): array
    {
        $this->ensureWallets($playerId);
        $stmt = $this->pdo()->prepare('SELECT currency_code, balance FROM player_currency_wallets WHERE player_id = :player_id ORDER BY currency_code ASC');
        $stmt->execute(['player_id' => $playerId]);

        return array_map(fn (array $row): array => [
            'currency_code' => (string) $row['currency_code'],
            'balance' => (int) $row['balance'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ensureWallet(int $playerId, string $currencyCode): void
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM player_currency_wallets WHERE player_id = :player_id AND currency_code = :currency_code LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        if ($stmt->fetchColumn()) {
            return;
        }

        $starting = match ($currencyCode) {
            'gold' => 500,
            'premium' => 10,
            default => 0,
        };

        $insert = $this->pdo()->prepare('INSERT INTO player_currency_wallets (player_id, currency_code, balance) VALUES (:player_id, :currency_code, :balance)');
        $insert->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
            'balance' => $starting,
        ]);
    }

    private function ledger(int $playerId, string $currencyCode, int $amount, int $balanceAfter, string $reasonCode, ?string $referenceType, ?string $referenceId, ?array $metadata): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO player_currency_ledger (player_id, currency_code, amount, balance_after, reason_code, reference_type, reference_id, metadata_json) VALUES (:player_id, :currency_code, :amount, :balance_after, :reason_code, :reference_type, :reference_id, :metadata_json)');
        $stmt->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reason_code' => $reasonCode,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata_json' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
