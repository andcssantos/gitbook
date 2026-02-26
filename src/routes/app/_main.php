<?php

function getPhpFiles(string $directory): array
{
    if (!is_dir($directory))
        return [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $files = [];
    foreach (new RegexIterator($iterator, '/\.php$/i') as $file)
        $files[] = $file->getPathname();

    return $files;
}

function loadPhpFiles(array $files): void
{
    foreach ($files as $file)
        require_once $file;
}

$dashboardPath = __DIR__ . '/dashboard';
$websitePath   = __DIR__ . '/website';

if (isset($_SESSION['user'])) {
    $dashboardRoutes = getPhpFiles($dashboardPath);
    loadPhpFiles($dashboardRoutes);
} else {
    $websiteRoutes = getPhpFiles($websitePath);
    loadPhpFiles($websiteRoutes);
}

require_once __DIR__ . '/Joker/RoutesJoker.php';