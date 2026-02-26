<?php

/**
 * Retorna todos os arquivos de rota de um diretório em ordem estável.
 */
function getRouteFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $files = [];
    foreach (new RegexIterator($iterator, '/\.php$/i') as $file) {
        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * Carrega um conjunto de arquivos de rota.
 */
function loadRouteFiles(array $files): void
{
    foreach ($files as $file) {
        require_once $file;
    }
}

/**
 * Centraliza os contextos de rotas por estado da aplicação.
 */
function loadContextRoutes(bool $isAuthenticated): void
{
    $contexts = [
        'website' => __DIR__ . '/website',
        'dashboard' => __DIR__ . '/dashboard',
    ];

    $selectedContext = $isAuthenticated ? 'dashboard' : 'website';
    loadRouteFiles(getRouteFiles($contexts[$selectedContext]));
}

loadContextRoutes(isset($_SESSION['user']));

// Rotas globais (válidas para qualquer contexto)
require_once __DIR__ . '/joker/RoutesJoker.php';
