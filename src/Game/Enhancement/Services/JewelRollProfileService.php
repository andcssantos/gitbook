<?php

namespace App\Game\Enhancement\Services;

class JewelRollProfileService
{
    public function blessBoostPercent(array $jewel): array
    {
        $config = $this->parseConfig($jewel);
        $profile = $config['bless_property_boost'] ?? $config['property_boost'] ?? [];

        return [
            'min' => (float) ($profile['min_percent'] ?? $profile['min'] ?? 3),
            'max' => (float) ($profile['max_percent'] ?? $profile['max'] ?? 8),
        ];
    }

    public function soulAffixBoostPercent(array $jewel): array
    {
        $config = $this->parseConfig($jewel);
        $profile = $config['soul_affix_boost'] ?? $config['affix_boost'] ?? [];

        return [
            'min' => (float) ($profile['min_percent'] ?? $profile['min'] ?? 5),
            'max' => (float) ($profile['max_percent'] ?? $profile['max'] ?? 15),
        ];
    }

    public function rollBlessBoostPercent(array $jewel): float
    {
        $range = $this->blessBoostPercent($jewel);
        $min = min($range['min'], $range['max']);
        $max = max($range['min'], $range['max']);

        return random_int((int) round($min * 100), (int) round($max * 100)) / 100;
    }

    public function rollSoulAffixBoostPercent(array $jewel): float
    {
        $range = $this->soulAffixBoostPercent($jewel);
        $min = min($range['min'], $range['max']);
        $max = max($range['min'], $range['max']);

        return random_int((int) round($min * 100), (int) round($max * 100)) / 100;
    }

    private function parseConfig(array $item): array
    {
        $raw = $item['base_config'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
