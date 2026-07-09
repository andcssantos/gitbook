<?php

namespace Tests\Modules;

use App\Modules\ModuleManifestLoader;
use PHPUnit\Framework\TestCase;

class ModuleManifestLoaderTest extends TestCase
{
    public function testLoadsLegacyManifestWithDefaults(): void
    {
        $path = __DIR__ . '/../fixtures/module.json';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode([
            'version' => '1.0.0',
            'seo' => ['title' => 'Fixture'],
            'styles' => [],
            'plugins' => [],
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ModuleManifestLoader())->load($path, 'home');

        $this->assertSame('home', $manifest->name);
        $this->assertSame('Fixture', $manifest->seo['title']);
        $this->assertNotEmpty($manifest->seo['meta']);
        $this->assertArrayHasKey('css', $manifest->assets);

        @unlink($path);
        @rmdir(dirname($path));
    }
}
