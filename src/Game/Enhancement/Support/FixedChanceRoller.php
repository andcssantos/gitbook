<?php

namespace App\Game\Enhancement\Support;

class FixedChanceRoller implements ChanceRoller
{
    private int $calls = 0;

    /** @param float|float[] $values */
    public function __construct(private float|array $values)
    {
        if (!is_array($this->values)) {
            $this->values = [$this->values];
        }
    }

    public function rollPercent(): float
    {
        $index = min($this->calls, count($this->values) - 1);
        $this->calls++;

        return max(0.0, min(100.0, (float) $this->values[$index]));
    }
}
