<?php

namespace Tests\Game\Items;

use App\Game\Items\Services\ItemStatRangeService;
use PHPUnit\Framework\TestCase;

class ItemStatRangeServiceTest extends TestCase
{
    public function testBlessCapIncreasesWithUpgradeLevel(): void
    {
        $service = new ItemStatRangeService();
        $item = [
            'quality_value' => 60.0,
            'quality_bucket' => 'rare',
            'properties' => [],
        ];

        $capAtFive = $service->allowedCapAtUpgradeLevel($item, 'attack_power', 5);
        $capAtTwenty = $service->allowedCapAtUpgradeLevel($item, 'attack_power', 20);

        $this->assertGreaterThan($capAtFive, $capAtTwenty);
    }

    public function testCappedBlessValueNeverExceedsLevelCap(): void
    {
        $service = new ItemStatRangeService();
        $item = [
            'quality_value' => 50.0,
            'quality_bucket' => 'magic',
            'properties' => [],
        ];

        $cap = $service->allowedCapAtUpgradeLevel($item, 'armor', 3);
        $next = $service->cappedBlessValue($cap - 1, 999, $item, 'armor', 3);

        $this->assertSame($cap, $next);
    }

    public function testScaleStatPreservesPercentWithinRangeOnRarityUpgrade(): void
    {
        $service = new ItemStatRangeService();
        $item = [
            'quality_value' => 40.0,
            'quality_bucket' => 'common',
            'properties' => [],
        ];

        $fromRange = $service->rangeForItem($item, 'attack_power');
        $midpoint = (int) round(($fromRange['min'] + $fromRange['max']) / 2);
        $scaled = $service->scaleStatForRarityUpgrade($midpoint, $item, 'attack_power', 'common', 'rare');

        $toItem = $item;
        $toItem['quality_bucket'] = 'rare';
        $toRange = $service->rangeForItem($toItem, 'attack_power');
        $expectedMid = (int) round(($toRange['min'] + $toRange['max']) / 2);

        $this->assertEqualsWithDelta($expectedMid, $scaled, 2.0);
    }
}
