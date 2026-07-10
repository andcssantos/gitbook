<?php

namespace App\Game\Enhancement\Support;

interface ChanceRoller
{
    /** Returns a float between 0 and 100 inclusive. */
    public function rollPercent(): float;
}
