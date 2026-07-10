<?php

namespace App\Game\Enhancement\Services;

use App\Game\Items\Repositories\ItemInstanceAffixRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;

class ItemEnhancementBonusService
{
    /** @var array<string, float> */
    private const AFFIX_BLESS_BONUS = [
        'masterwork' => 10.0,
    ];

    public function __construct(
        private ?ItemInstancePropertyRepository $properties = null,
        private ?ItemInstanceAffixRepository $affixes = null
    ) {
        $this->properties ??= new ItemInstancePropertyRepository();
        $this->affixes ??= new ItemInstanceAffixRepository();
    }

    public function blessSuccessBonusPercent(int $itemInstanceId): float
    {
        $bonus = 0.0;

        $property = $this->properties->findByItemAndCode($itemInstanceId, 'bless_success_bonus');
        if ($property !== null) {
            $bonus += (float) ($property['numeric_value'] ?? $property['integer_value'] ?? 0);
        }

        foreach ($this->affixes->listForItem($itemInstanceId) as $affix) {
            $code = (string) ($affix['code'] ?? '');
            if (isset(self::AFFIX_BLESS_BONUS[$code])) {
                $bonus += self::AFFIX_BLESS_BONUS[$code];
            }
        }

        return round(max(0.0, min(25.0, $bonus)), 2);
    }
}
