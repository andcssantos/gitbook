<?php

namespace Tests\Game\Items;

use App\Game\Items\Services\ItemPowerService;
use PHPUnit\Framework\TestCase;

class ItemPowerServiceTest extends TestCase
{
    public function testHigherRarityAndUpgradeIncreasePower(): void
    {
        $service = new ItemPowerService();

        $common = [
            'quality_bucket' => 'common',
            'properties' => [
                ['code' => 'attack_power', 'value' => 20, 'source' => 'base'],
            ],
            'affixes' => [],
            'sockets' => [],
        ];

        $legendary = [
            'quality_bucket' => 'legendary',
            'properties' => [
                ['code' => 'attack_power', 'value' => 20, 'source' => 'base'],
                ['code' => 'upgrade_level', 'value' => 3, 'source' => 'upgrade'],
            ],
            'affixes' => [
                ['property_code' => 'critical_chance', 'value' => 5],
            ],
            'sockets' => [
                ['index' => 0, 'gem' => ['public_id' => 'gem-1']],
            ],
        ];

        $this->assertGreaterThan($service->forItem($common), $service->forItem($legendary));
    }

    public function testEquippedPlayerPowerAggregatesEquippedItems(): void
    {
        $service = new ItemPowerService();

        $equipment = [
            [
                'item' => [
                    'quality_bucket' => 'rare',
                    'properties' => [['code' => 'armor', 'value' => 30, 'source' => 'base']],
                    'affixes' => [],
                    'sockets' => [],
                ],
            ],
            [
                'item' => [
                    'quality_bucket' => 'magic',
                    'properties' => [['code' => 'attack_power', 'value' => 18, 'source' => 'base']],
                    'affixes' => [],
                    'sockets' => [],
                ],
            ],
            ['item' => null],
        ];

        $stats = [
            ['code' => 'attack_power', 'value' => 18],
            ['code' => 'armor', 'value' => 30],
            ['code' => 'max_health', 'value' => 40],
        ];

        $power = $service->forEquippedPlayer($equipment, $stats);

        $this->assertSame(18, $power['attack']);
        $this->assertSame(30, $power['armor']);
        $this->assertSame(40, $power['life']);
        $this->assertGreaterThan(0, (int) ($power['equipment_total'] ?? 0));
        $this->assertArrayHasKey('attribute_total', $power);
        $this->assertSame(
            (int) $power['equipment_total'] + (int) $power['attribute_total'],
            (int) $power['total']
        );
    }
}
