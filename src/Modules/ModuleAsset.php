<?php

namespace App\Modules;

class ModuleAsset
{
    public function __construct(
        public readonly string $src,
        public readonly string $type = '',
        public readonly bool $defer = false,
        public readonly bool $async = false,
        public readonly string $media = '',
        public readonly bool $module = false,
        public readonly string $integrity = '',
        public readonly string $crossorigin = ''
    ) {
    }
}
