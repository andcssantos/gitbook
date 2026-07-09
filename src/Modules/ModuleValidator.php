<?php

namespace App\Modules;

use App\Utils\Config;

class ModuleValidator
{
    public function validate(ModuleDefinition $module): array
    {
        $errors = [];
        $warnings = [];
        $manifest = $module->manifest;

        foreach (['name', 'version', 'lang', 'charset'] as $field) {
            if ($manifest->{$field} === '') {
                $errors[] = "Campo obrigatorio vazio: {$field}";
            }
        }

        if (!is_file($module->contentPath)) {
            $errors[] = 'Arquivo index do modulo nao encontrado.';
        }

        if (!is_file($module->manifestPath)) {
            $warnings[] = 'Manifest module.json ausente; defaults serao usados.';
        }

        foreach ($this->declaredAssets($module) as $asset) {
            if ($asset['external']) {
                if (!$this->externalAllowed($asset['path'])) {
                    $errors[] = "Host externo nao permitido: {$asset['path']}";
                }
                continue;
            }

            if (!is_file($asset['absolute'])) {
                $warnings[] = "Asset declarado nao encontrado: {$asset['relative']}";
            }
        }

        return [
            'id' => $module->id(),
            'errors' => $errors,
            'warnings' => $warnings,
            'ok' => $errors === [],
        ];
    }

    public function assetReport(ModuleDefinition $module): array
    {
        $assets = [];

        foreach ($this->physicalAssets($module) as $path) {
            $assets[] = [
                'path' => str_replace('\\', '/', substr($path, strlen($module->rootPath) + 1)),
                'bytes' => filesize($path) ?: 0,
                'hash' => substr(hash_file('sha256', $path), 0, 12),
            ];
        }

        return [
            'id' => $module->id(),
            'content' => str_replace('\\', '/', $module->contentPath),
            'manifest' => str_replace('\\', '/', $module->manifestPath),
            'assets' => $assets,
            'total_bytes' => array_sum(array_column($assets, 'bytes')),
            'validation' => $this->validate($module),
        ];
    }

    private function declaredAssets(ModuleDefinition $module): array
    {
        $assets = [];
        $manifest = $module->manifest;

        foreach ((array) ($manifest->assets['css'] ?? []) as $asset) {
            $file = is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset;
            $assets[] = $this->assetInfo($module, 'css', $file);
        }

        foreach ((array) ($manifest->assets['js'] ?? []) as $asset) {
            $file = is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset;
            $assets[] = $this->assetInfo($module, 'script', $file);
        }

        foreach ((array) ($manifest->dependencies['libs']['css'] ?? []) as $asset) {
            $assets[] = $this->dependencyInfo((string) $asset);
        }

        foreach ((array) ($manifest->dependencies['libs']['js'] ?? []) as $asset) {
            $file = is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset;
            $assets[] = $this->dependencyInfo($file);
        }

        return array_filter($assets, fn (array $asset): bool => $asset['path'] !== '');
    }

    private function assetInfo(ModuleDefinition $module, string $folder, string $file): array
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');

        return [
            'path' => $file,
            'relative' => "assets/{$folder}/{$file}",
            'absolute' => $module->assetPath($folder, $file),
            'external' => false,
        ];
    }

    private function dependencyInfo(string $asset): array
    {
        $asset = trim($asset);
        $external = (bool) preg_match('/^https?:\/\//i', $asset);
        $base = trim((string) Config::get('modules.libs_base_url', 'assets/libs'), '/');
        $relative = ltrim(str_replace('\\', '/', $asset), '/');

        if (!$external && str_starts_with($relative, 'assets/')) {
            return [
                'path' => $asset,
                'relative' => $relative,
                'absolute' => __DIR__ . '/../../public/' . $relative,
                'external' => false,
            ];
        }

        return [
            'path' => $asset,
            'relative' => $external ? $asset : "{$base}/{$relative}",
            'absolute' => __DIR__ . '/../../public/' . $base . '/' . $relative,
            'external' => $external,
        ];
    }

    private function physicalAssets(ModuleDefinition $module): array
    {
        $root = $module->rootPath . '/assets';
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function externalAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $allowed = (array) Config::get('modules.allowed_external_hosts', []);

        return is_string($host) && in_array($host, $allowed, true);
    }
}
