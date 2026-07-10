<?php

namespace App\Game\Materials\Services;

use App\Utils\Config;

class MaterialStashTabResolver
{
    public function tabForFamilyCode(string $familyCode, bool $fromSocketGem = false): string
    {
        if ($fromSocketGem) {
            return 'gems';
        }

        $map = (array) Config::get('materials.family_tab_map', []);

        return (string) ($map[$familyCode] ?? 'fragments');
    }

    public function tabs(): array
    {
        return array_values((array) Config::get('materials.tabs', []));
    }
}
