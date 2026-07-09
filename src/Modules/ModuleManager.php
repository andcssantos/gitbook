<?php

namespace App\Modules;

use App\Utils\Config;
use App\Utils\Functions\CacheManager;
use RuntimeException;

class ModuleManager
{
    private string $basePath;
    private string $assetBaseUrl;
    private string $manifestFile;
    private string $indexFile;
    private CacheManager $cache;

    public function __construct(?CacheManager $cache = null)
    {
        $this->basePath = rtrim((string) Config::get('modules.base_path', __DIR__ . '/../../public/views'), '/\\');
        $this->assetBaseUrl = trim((string) Config::get('modules.asset_base_url', 'views'), '/');
        $this->manifestFile = (string) Config::get('modules.manifest_file', 'module.json');
        $this->indexFile = (string) Config::get('modules.index_file', 'index.php');
        $this->cache = $cache ?? new CacheManager('modules', (int) Config::get('modules.cache_ttl', 3600));
    }

    public function resolve(string $domain, string $layout, string $module, string $page = 'index.php'): ModuleDefinition
    {
        $domain = $this->safeSegment(trim($domain, '/'));
        $layout = $this->safeSegment($layout);
        $module = $this->safeModule($module);
        $page = $this->safeSegment(pathinfo($page, PATHINFO_FILENAME)) . '.php';

        $root = "{$this->basePath}/{$domain}/{$layout}/{$module}";
        $content = "{$root}/{$page}";
        $manifest = "{$root}/assets/{$this->manifestFile}";

        if (!is_file($content)) {
            $module = (string) ($_ENV['MOD_ERROR_404'] ?? '404');
            $root = "{$this->basePath}/" . ($_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'app') . "/{$layout}/{$module}";
            $content = "{$root}/{$this->indexFile}";
            $manifest = "{$root}/assets/{$this->manifestFile}";
        }

        if (!is_file($content)) {
            throw new RuntimeException("Modulo nao encontrado: {$layout}/{$module}");
        }

        $cacheKey = 'manifest:' . md5($manifest . '|' . (is_file($manifest) ? filemtime($manifest) : 'missing'));
        $manifestData = $this->cache->remember(
            $cacheKey,
            fn (): array => (new ModuleManifestLoader())->load($manifest, $module)->toArray(),
            'long',
            ['tags' => ['modules', "module:{$domain}.{$layout}.{$module}"]]
        );
        $manifestObject = ModuleManifest::fromArray((array) $manifestData);

        return new ModuleDefinition($domain, $layout, $module, $page, $root, $content, $manifest, $manifestObject);
    }

    public function list(): array
    {
        $modules = [];
        foreach (glob($this->basePath . '/*/*/*', GLOB_ONLYDIR) ?: [] as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($this->basePath) + 1));
            [$domain, $layout, $module] = array_pad(explode('/', $relative), 3, '');
            $manifest = "{$path}/assets/{$this->manifestFile}";
            $modules[] = [
                'id' => "{$domain}.{$layout}.{$module}",
                'domain' => $domain,
                'layout' => $layout,
                'module' => $module,
                'manifest' => is_file($manifest),
                'index' => is_file("{$path}/{$this->indexFile}"),
            ];
        }

        return $modules;
    }

    public function inspect(string $id): array
    {
        [$domain, $layout, $module] = $this->parseId($id);

        return (new ModuleValidator())->assetReport($this->resolve($domain, $layout, $module));
    }

    public function build(): array
    {
        $report = [];
        $validator = new ModuleValidator();

        foreach ($this->list() as $module) {
            $definition = $this->resolve($module['domain'], $module['layout'], $module['module']);
            $report[] = $validator->assetReport($definition);
        }

        $target = __DIR__ . '/../../bootstrap/cache/modules.php';
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, "<?php\n\nreturn " . var_export($report, true) . ";\n");

        return $report;
    }

    public function versionedAsset(ModuleDefinition $module, string $folder, string $file): ?string
    {
        $file = $this->safeAssetFile($file);
        $path = $module->assetPath($folder, $file);

        if (!is_file($path)) {
            return null;
        }

        $url = $module->relativeAssetPath($this->assetBaseUrl, $folder, $file);
        return $url . '?v=' . substr(hash_file('sha256', $path), 0, 12);
    }

    private function safeSegment(string $segment): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_\-]/', '', $segment);

        return $segment !== '' ? $segment : 'index';
    }

    private function safeModule(string $module): string
    {
        $module = trim($module, '/');
        $module = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $module);

        return $module !== '' ? $module : 'home';
    }

    private function safeAssetFile(string $file): string
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');
        if (str_contains($file, '../')) {
            return '';
        }

        return $file;
    }

    private function parseId(string $id): array
    {
        $parts = explode('.', str_replace('/', '.', trim($id, './')));

        if (count($parts) === 2) {
            array_unshift($parts, $_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'app');
        }

        return array_pad($parts, 3, '');
    }
}
