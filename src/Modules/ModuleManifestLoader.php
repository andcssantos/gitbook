<?php

namespace App\Modules;

use App\Utils\Config;
use JsonException;
use RuntimeException;

class ModuleManifestLoader
{
    public function load(string $path, string $fallbackName = ''): ModuleManifest
    {
        $raw = [];

        if (is_file($path)) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $raw = is_array($decoded) ? $decoded : [];
            } catch (JsonException $e) {
                throw new RuntimeException("Manifest JSON invalido em {$path}: " . $e->getMessage(), 0, $e);
            }
        }

        return $this->normalize($raw, $fallbackName);
    }

    private function normalize(array $raw, string $fallbackName): ModuleManifest
    {
        $name = $this->safeName((string) ($raw['name'] ?? $fallbackName ?: 'module'));
        $seo = $this->normalizeSeo((array) ($raw['seo'] ?? []), $raw);
        $legacyPlugins = (array) ($raw['plugins'] ?? $raw['scripts'] ?? []);
        $legacyStyles = (array) ($raw['styles'] ?? []);

        $security = (array) ($raw['security'] ?? []);
        $security['auth'] = (bool) ($security['auth'] ?? $raw['auth'] ?? false);
        $security['roles'] = (array) ($security['roles'] ?? $raw['roles'] ?? []);
        $security['middlewares'] = (array) ($security['middlewares'] ?? $raw['middlewares'] ?? []);

        return new ModuleManifest(
            name: $name,
            version: (string) ($raw['version'] ?? ($_ENV['DEFAULT_VERSION'] ?? '1.0.0')),
            description: (string) ($raw['description'] ?? $raw['descript'] ?? 'Sem descricao'),
            lang: (string) ($raw['lang'] ?? ($_ENV['DEFAULT_LANG'] ?? 'pt-BR')),
            charset: (string) ($raw['charset'] ?? ($_ENV['DEFAULT_CHARSET'] ?? 'UTF-8')),
            route: (array) ($raw['route'] ?? []),
            seo: $seo,
            cache: array_merge([
                'html' => 0,
                'assets' => 86400,
                'preload' => true,
            ], (array) ($raw['cache'] ?? [])),
            assets: $this->normalizeAssets((array) ($raw['assets'] ?? []), $legacyStyles, $legacyPlugins),
            dependencies: (array) ($raw['dependencies'] ?? ['libs' => []]),
            security: $security,
            data: (array) ($raw['data'] ?? []),
            legacy: [
                'plugins' => $legacyPlugins,
                'styles' => $legacyStyles,
            ]
        );
    }

    private function normalizeSeo(array $seo, array $raw): array
    {
        $meta = (array) ($seo['meta'] ?? []);
        $hasViewport = false;

        foreach ($meta as $item) {
            if (is_array($item) && ($item['name'] ?? null) === 'viewport') {
                $hasViewport = true;
                break;
            }
        }

        if (!$hasViewport) {
            array_unshift($meta, [
                'name' => 'viewport',
                'content' => 'width=device-width, initial-scale=1.0',
            ]);
        }

        return [
            'title' => (string) ($seo['title'] ?? $raw['title'] ?? ($_ENV['DEFAULT_TITLE'] ?? Config::get('app.name', 'GitBook'))),
            'description' => (string) ($seo['description'] ?? $raw['description'] ?? ''),
            'image' => $seo['image'] ?? null,
            'favicon' => (string) ($seo['favicon'] ?? 'favicon.ico'),
            'canonical' => $seo['canonical'] ?? null,
            'meta' => array_values($meta),
        ];
    }

    private function normalizeAssets(array $assets, array $legacyStyles, array $legacyPlugins): array
    {
        $css = (array) ($assets['css'] ?? []);
        $js = (array) ($assets['js'] ?? []);

        foreach ($legacyStyles as $style) {
            $css[] = $style;
        }

        foreach ($legacyPlugins as $plugin) {
            $js[] = $plugin;
        }

        return [
            'css' => $css,
            'js' => $js,
            'preload' => (array) ($assets['preload'] ?? []),
            'prefetch' => (array) ($assets['prefetch'] ?? []),
        ];
    }

    private function safeName(string $name): string
    {
        $name = trim($name, '/');
        $name = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $name);

        return $name !== '' ? $name : 'module';
    }
}
