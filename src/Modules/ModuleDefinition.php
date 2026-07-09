<?php

namespace App\Modules;

class ModuleDefinition
{
    public function __construct(
        public readonly string $domain,
        public readonly string $layout,
        public readonly string $name,
        public readonly string $page,
        public readonly string $rootPath,
        public readonly string $contentPath,
        public readonly string $manifestPath,
        public readonly ModuleManifest $manifest
    ) {
    }

    public function id(): string
    {
        return trim($this->domain, '/') . '.' . $this->layout . '.' . $this->name;
    }

    public function assetPath(string $folder, string $file): string
    {
        return $this->rootPath . '/assets/' . trim($folder, '/') . '/' . ltrim($file, '/');
    }

    public function relativeAssetPath(string $assetBaseUrl, string $folder, string $file): string
    {
        return trim($assetBaseUrl, '/') . '/'
            . trim($this->domain, '/') . '/'
            . $this->layout . '/'
            . $this->name . '/assets/'
            . trim($folder, '/') . '/'
            . ltrim($file, '/');
    }
}
