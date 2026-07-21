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
        if (!$this->tableExists('player_currency_wallets')) {
            return;
        }

        foreach (array_keys((array) Config::get('market.currencies', [])) as $currencyCode) {
            $this->ensureWallet($playerId, (string) $currencyCode);
        }
    }

    public function balance(int $playerId, string $currencyCode): float
    {
        if (!$this->tableExists('player_currency_wallets')) {
            return 0.0;
        }

        $this->ensureWallet($playerId, $currencyCode);
        $stmt = $this->pdo()->prepare('SELECT balance FROM player_currency_wallets WHERE player_id = :player_id AND currency_code = :currency_code LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        return (float) $stmt->fetchColumn();
    }

    public function credit(int $playerId, string $currencyCode, int|float $amount, string $reasonCode, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): float
    {
        if (!$this->tableExists('player_currency_wallets')) {
            throw new \App\Game\Inventory\InventoryException('MARKET_WALLETS_NOT_AVAILABLE', 'Currency wallets are not available.', 500);
        }

        $amount = $this->normalizeAmount($amount);
        if ($amount <= 0.0) {
            return $this->balance($playerId, $currencyCode);
        }

        $this->ensureWallet($playerId, $currencyCode);
        $stmt = $this->pdo()->prepare('UPDATE player_currency_wallets SET balance = balance + :amount WHERE player_id = :player_id AND currency_code = :currency_code');
        $stmt->execute([
            'amount' => $this->decimal($amount),
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        $balanceAfter = $this->balance($playerId, $currencyCode);
        $this->ledger($playerId, $currencyCode, $amount, $balanceAfter, $reasonCode, $referenceType, $referenceId, $metadata);

        return $balanceAfter;
    }

    public function debit(int $playerId, string $currencyCode, int|float $amount, string $reasonCode, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): float
    {
        if (!$this->tableExists('player_currency_wallets')) {
            throw new \App\Game\Inventory\InventoryException('MARKET_WALLETS_NOT_AVAILABLE', 'Currency wallets are not available.', 500);
        }

        $amount = $this->normalizeAmount($amount);
        if ($amount <= 0.0) {
            return $this->balance($playerId, $currencyCode);
        }

        $current = $this->balance($playerId, $currencyCode);
        if (($current + 0.00001) < $amount) {
            throw new \App\Game\Inventory\InventoryException('MARKET_INSUFFICIENT_FUNDS', 'Saldo insuficiente para esta operacao.', 422);
        }

        $stmt = $this->pdo()->prepare('UPDATE player_currency_wallets SET balance = balance - :amount WHERE player_id = :player_id AND currency_code = :currency_code');
        $stmt->execute([
            'amount' => $this->decimal($amount),
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
        ]);

        $balanceAfter = $this->balance($playerId, $currencyCode);
        $this->ledger($playerId, $currencyCode, -$amount, $balanceAfter, $reasonCode, $referenceType, $referenceId, $metadata);

        return $balanceAfter;
    }

    public function walletsForPlayer(int $playerId): array
    {
        if (!$this->tableExists('player_currency_wallets')) {
            return [];
        }

        $this->ensureWallets($playerId);
        $stmt = $this->pdo()->prepare('SELECT currency_code, balance FROM player_currency_wallets WHERE player_id = :player_id ORDER BY currency_code ASC');
        $stmt->execute(['player_id' => $playerId]);

        return array_map(fn (array $row): array => [
            'currency_code' => (string) $row['currency_code'],
            'balance' => (float) $row['balance'],
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

    private function ledger(int $playerId, string $currencyCode, float $amount, float $balanceAfter, string $reasonCode, ?string $referenceType, ?string $referenceId, ?array $metadata): void
    {
        if (!$this->tableExists('player_currency_ledger')) {
            return;
        }

        $stmt = $this->pdo()->prepare('INSERT INTO player_currency_ledger (player_id, currency_code, amount, balance_after, reason_code, reference_type, reference_id, metadata_json) VALUES (:player_id, :currency_code, :amount, :balance_after, :reason_code, :reference_type, :reference_id, :metadata_json)');
        $stmt->execute([
            'player_id' => $playerId,
            'currency_code' => $currencyCode,
            'amount' => $this->decimal($amount),
            'balance_after' => $this->decimal($balanceAfter),
            'reason_code' => $reasonCode,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata_json' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function normalizeAmount(int|float $amount): float
    {
        return round((float) $amount, 4);
    }

    private function decimal(float $amount): string
    {
        return number_format($this->normalizeAmount($amount), 4, '.', '');
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}
