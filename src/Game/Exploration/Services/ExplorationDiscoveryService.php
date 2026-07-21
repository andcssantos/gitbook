<?php

namespace App\Game\Exploration\Services;

class ExplorationDiscoveryService
{
    public function __construct(private ?ExplorationBiomeCatalogService $catalog = null)
    {
        $this->catalog ??= new ExplorationBiomeCatalogService();
    }

    public function isDiscovered(
        string $biomeCode,
        float $playerX,
        float $playerY,
        float $objectX,
        float $objectY,
        int $revealTier,
        ?float $discoveryRadius = null
    ): bool {
        if ($revealTier > 0) {
            return true;
        }

        $radius = $discoveryRadius ?? $this->catalog->discoveryRadius($biomeCode);
        $distance = $this->distance($playerX, $playerY, $objectX, $objectY);

        return $distance <= $radius;
    }

    public function distance(float $fromX, float $fromY, float $toX, float $toY): float
    {
        $dx = $toX - $fromX;
        $dy = $toY - $fromY;

        return sqrt(($dx * $dx) + ($dy * $dy));
    }
}
