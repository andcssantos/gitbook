<?php

return function (PDO $pdo): void {
    $email = 'local@evolvaxe.test';
    $passwordHash = password_hash('evolvaxe-local', PASSWORD_ARGON2ID);

    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $accountId = $stmt->fetchColumn();

    if ($accountId) {
        $update = $pdo->prepare('UPDATE accounts SET display_name = :display_name, password_hash = :password_hash, status = :status, deleted_at = NULL WHERE id = :id');
        $update->execute([
            'display_name' => 'Local Tester',
            'password_hash' => $passwordHash,
            'status' => 'active',
            'id' => $accountId,
        ]);
        $accountId = (int) $accountId;
    } else {
        $insert = $pdo->prepare('INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES (:public_id, :display_name, :email, :password_hash, :status)');
        $insert->execute([
            'public_id' => '00000000-0000-4000-8000-000000000001',
            'display_name' => 'Local Tester',
            'email' => $email,
            'password_hash' => $passwordHash,
            'status' => 'active',
        ]);
        $accountId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('SELECT id FROM players WHERE account_id = :account_id AND name = :name LIMIT 1');
    $stmt->execute([
        'account_id' => $accountId,
        'name' => 'LocalHero',
    ]);
    $playerId = $stmt->fetchColumn();

    if ($playerId) {
        $update = $pdo->prepare('UPDATE players SET status = :status, level = :level, experience = :experience, base_expedition_seconds = :base_expedition_seconds WHERE id = :id');
        $update->execute([
            'status' => 'active',
            'level' => 1,
            'experience' => 0,
            'base_expedition_seconds' => 60,
            'id' => $playerId,
        ]);
        (new \App\Game\Inventory\Services\StarterInventoryService($pdo))->ensureForPlayer((int) $playerId);
        return;
    }

    $insert = $pdo->prepare('INSERT INTO players (public_id, account_id, name, avatar_key, level, experience, base_expedition_seconds, status) VALUES (:public_id, :account_id, :name, :avatar_key, :level, :experience, :base_expedition_seconds, :status)');
    $insert->execute([
        'public_id' => '00000000-0000-4000-8000-000000000101',
        'account_id' => $accountId,
        'name' => 'LocalHero',
        'avatar_key' => 'starter',
        'level' => 1,
        'experience' => 0,
        'base_expedition_seconds' => 60,
        'status' => 'active',
    ]);

    (new \App\Game\Inventory\Services\StarterInventoryService($pdo))->ensureForPlayer((int) $pdo->lastInsertId());
};
