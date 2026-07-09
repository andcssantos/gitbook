<?php

namespace App\Modules;

use App\Utils\Config;
use RuntimeException;

class ComponentManager
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim((string) Config::get('modules.base_path', __DIR__ . '/../../public/views'), '/\\')
            . '/' . ($_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'app') . '/shared/components';
    }

    public function render(string $name, array $data = []): string
    {
        $name = $this->safeName($name);
        $path = "{$this->basePath}/{$name}/index.php";

        if (!is_file($path)) {
            throw new RuntimeException("Componente nao encontrado: {$name}");
        }

        extract($data);
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }

    public function make(string $name): string
    {
        $name = $this->safeName($name);
        $root = "{$this->basePath}/{$name}";

        foreach ([$root, "{$root}/assets/css", "{$root}/assets/script"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->writeIfMissing("{$root}/index.php", "<div data-component=\"{$name}\">\n    <?= htmlspecialchars((string) (\$text ?? '{$name}'), ENT_QUOTES, 'UTF-8') ?>\n</div>\n");
        $this->writeIfMissing("{$root}/assets/css/style.css", "[data-component=\"{$name}\"] {\n    display: block;\n}\n");
        $this->writeIfMissing("{$root}/assets/script/main.js", "export function mount() {}\n");

        return $root;
    }

    private function safeName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        $name = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $name);

        if ($name === '' || str_contains($name, '../')) {
            throw new RuntimeException('Nome de componente invalido.');
        }

        return $name;
    }

    private function writeIfMissing(string $path, string $content): void
    {
        if (!is_file($path)) {
            file_put_contents($path, $content);
        }
    }
}
