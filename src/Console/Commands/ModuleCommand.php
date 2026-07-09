<?php

namespace App\Console\Commands;

use App\Modules\ModuleManager;
use App\Modules\ModuleValidator;
use App\Modules\ComponentManager;
use App\Utils\Config;
use App\Utils\Functions\CacheManager;
use Throwable;

class ModuleCommand
{
    public function list(): int
    {
        foreach ((new ModuleManager())->list() as $module) {
            echo sprintf(
                "%s  manifest:%s index:%s\n",
                $module['id'],
                $module['manifest'] ? 'ok' : 'missing',
                $module['index'] ? 'ok' : 'missing'
            );
        }

        return 0;
    }

    public function validate(): int
    {
        $manager = new ModuleManager();
        $validator = new ModuleValidator();
        $errors = 0;

        foreach ($manager->list() as $module) {
            try {
                $definition = $manager->resolve($module['domain'], $module['layout'], $module['module']);
                $result = $validator->validate($definition);
                $errors += count($result['errors']);
                echo ($result['ok'] ? '[ok] ' : '[erro] ') . $module['id'] . "\n";
                foreach ($result['errors'] as $error) {
                    echo "  erro: {$error}\n";
                }
                foreach ($result['warnings'] as $warning) {
                    echo "  aviso: {$warning}\n";
                }
            } catch (Throwable $e) {
                $errors++;
                echo "[erro] {$module['id']}: {$e->getMessage()}\n";
            }
        }

        return $errors > 0 ? 1 : 0;
    }

    public function inspect(string $id): int
    {
        if ($id === '') {
            throw new \RuntimeException('Informe o modulo. Ex: app.website.home');
        }

        $report = (new ModuleManager())->inspect($id);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        return 0;
    }

    public function build(): int
    {
        $report = (new ModuleManager())->build();
        $total = array_sum(array_column($report, 'total_bytes'));

        echo "Build de modulos gerado: " . count($report) . " modulos, {$total} bytes em assets.\n";
        return 0;
    }

    public function clear(): int
    {
        (new CacheManager('modules'))->flushTag('modules');
        (new CacheManager('modules/html'))->flushTag('modules');
        echo "Cache de modulos limpo.\n";

        return 0;
    }

    public function make(string $name): int
    {
        $name = trim($name, '/');
        if ($name === '') {
            throw new \RuntimeException('Informe o modulo no formato layout/nome ou domain/layout/nome.');
        }

        $parts = explode('/', $name);
        if (count($parts) === 2) {
            $domain = $_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'app';
            [$layout, $module] = $parts;
        } else {
            [$domain, $layout, $module] = array_pad($parts, 3, '');
        }

        foreach ([$domain, $layout, $module] as $part) {
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $part)) {
                throw new \RuntimeException('Nome de modulo invalido.');
            }
        }

        $base = rtrim((string) Config::get('modules.base_path', __DIR__ . '/../../../public/views'), '/\\');
        $root = "{$base}/{$domain}/{$layout}/{$module}";
        $assets = "{$root}/assets";

        foreach ([$root, "{$assets}/css", "{$assets}/script"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->writeIfMissing("{$root}/index.php", "<section class=\"module-shell\">\n    <h1>" . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . "</h1>\n</section>\n");
        $this->writeIfMissing("{$assets}/css/style.css", "[data-module=\"{$domain}.{$layout}.{$module}\"] {\n    display: block;\n}\n");
        $this->writeIfMissing("{$assets}/script/main.js", "export function mount() {}\n\nmount();\n");
        $this->writeIfMissing("{$assets}/module.json", json_encode([
            'name' => $module,
            'version' => '1.0.0',
            'description' => "Modulo {$module}",
            'lang' => 'pt-BR',
            'charset' => 'UTF-8',
            'type' => 'page',
            'layout' => $layout,
            'auth' => $layout === 'dashboard',
            'cache' => ['html' => 0, 'assets' => 86400, 'preload' => true],
            'seo' => [
                'title' => ucfirst($module),
                'description' => "Modulo {$module}",
                'meta' => [['name' => 'robots', 'content' => $layout === 'dashboard' ? 'noindex, nofollow' : 'index, follow']],
            ],
            'assets' => ['css' => [], 'js' => [], 'preload' => [], 'prefetch' => []],
            'dependencies' => ['libs' => [], 'modules' => []],
            'data' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        echo "Modulo criado: {$root}\n";
        return 0;
    }

    public function makeComponent(string $name): int
    {
        echo "Componente criado: " . (new ComponentManager())->make($name) . "\n";

        return 0;
    }

    private function writeIfMissing(string $path, string $content): void
    {
        if (!is_file($path)) {
            file_put_contents($path, $content);
        }
    }
}
