<?php

namespace App\Game\Enhancement\Support;

class RandomChanceRoller implements ChanceRoller
{
    public function rollPercent(): float
    {
        return random_int(1, 10000) / 100.0;
    }
}
