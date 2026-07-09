<?php

namespace App\Utils\Construct;

use App\Modules\ModuleManifestLoader;

class JModule
{
    private string $jsonPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
    }

    public function loadAndValidateJson(): object
    {
        $fallbackName = basename(dirname(dirname($this->jsonPath)));

        return (new ModuleManifestLoader())
            ->load($this->jsonPath, $fallbackName)
            ->toObject();
    }
}
