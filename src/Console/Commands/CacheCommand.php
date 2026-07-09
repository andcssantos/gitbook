<?php

namespace App\Console\Commands;

use App\Utils\Config;
use App\Utils\Functions\CacheManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CacheCommand
{
    public function clean(): int
    {
        (new CacheManager())->clean();
        echo "Cache limpo: expirados, corrompidos, locks antigos e diretorios vazios removidos.\n";

        return 0;
    }

    public function clear(): int
    {
        $path = (string) Config::get('cache.path', __DIR__ . '/../../../src/.cache');

        if (is_dir($path)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($path);
        }

        echo "Cache removido.\n";
        return 0;
    }
}
