<?php

namespace Tests\Modules;

use App\Modules\ModuleManager;
use App\Utils\Config;
use PHPUnit\Framework\TestCase;

class ModuleManagerTest extends TestCase
{
    public function testResolvesExistingModuleAndVersionsAsset(): void
    {
        Config::load(__DIR__ . '/../../');

        $manager = new ModuleManager();
        $module = $manager->resolve('app', 'website', 'home');

        $this->assertSame('app.website.home', $module->id());
        $this->assertFileExists($module->contentPath);

        $style = $manager->versionedAsset($module, 'css', 'style.css');

        $this->assertIsString($style);
        $this->assertStringContainsString('?v=', $style);
    }
}
