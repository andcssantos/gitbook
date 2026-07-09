<?php

namespace App\Modules;

class ModuleManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $lang,
        public readonly string $charset,
        public readonly array $route,
        public readonly array $seo,
        public readonly array $cache,
        public readonly array $assets,
        public readonly array $dependencies,
        public readonly array $security,
        public readonly array $data,
        public readonly array $legacy
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? 'module'),
            version: (string) ($data['version'] ?? '1.0.0'),
            description: (string) ($data['description'] ?? ''),
            lang: (string) ($data['lang'] ?? 'pt-BR'),
            charset: (string) ($data['charset'] ?? 'UTF-8'),
            route: (array) ($data['route'] ?? []),
            seo: (array) ($data['seo'] ?? []),
            cache: (array) ($data['cache'] ?? []),
            assets: (array) ($data['assets'] ?? []),
            dependencies: (array) ($data['dependencies'] ?? []),
            security: (array) ($data['security'] ?? []),
            data: (array) ($data['data'] ?? []),
            legacy: (array) ($data['legacy'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'lang' => $this->lang,
            'charset' => $this->charset,
            'route' => $this->route,
            'seo' => $this->seo,
            'cache' => $this->cache,
            'assets' => $this->assets,
            'dependencies' => $this->dependencies,
            'security' => $this->security,
            'data' => $this->data,
            'legacy' => $this->legacy,
        ];
    }

    public function toObject(): object
    {
        return (object) [
            'name' => $this->name,
            'version' => $this->version,
            'descript' => $this->description,
            'description' => $this->description,
            'lang' => $this->lang,
            'charset' => $this->charset,
            'route' => $this->route,
            'seo' => (object) $this->seo,
            'cache' => (object) $this->cache,
            'assets' => json_decode(json_encode($this->assets, JSON_THROW_ON_ERROR)),
            'dependencies' => json_decode(json_encode($this->dependencies, JSON_THROW_ON_ERROR)),
            'security' => json_decode(json_encode($this->security, JSON_THROW_ON_ERROR)),
            'data' => json_decode(json_encode($this->data, JSON_THROW_ON_ERROR)),
            'plugins' => $this->legacy['plugins'] ?? [],
            'styles' => $this->legacy['styles'] ?? [],
        ];
    }
}
