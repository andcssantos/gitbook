<?php

declare(strict_types=1);

/**
 * Atalho local: cria sessão do usuário seed e redireciona para /dashboard.
 * Só responde em 127.0.0.1 / ::1.
 */
require_once __DIR__ . '/../bootstrap/init.php';

use App\Game\Identity\Services\AuthService;

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

try {
    (new AuthService())->login('local@evolvaxe.test', 'evolvaxe-local');
    header('Location: /dashboard', true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Login local falhou: ' . $e->getMessage();
    exit;
}
