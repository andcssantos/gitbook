<?php

namespace App\Game\Expeditions\Services;

class ExpeditionArenaRng
{
    private int $counter = 0;

    public function __construct(private string $seed)
    {
    }

    public function nextFloat(): float
    {
        $hash = hash('sha256', $this->seed . ':' . $this->counter);
        $this->counter++;

        return hexdec(substr($hash, 0, 8)) / 0xffffffff;
    }

    public function rollChance(float $chance): bool
    {
        return $this->nextFloat() < max(0.0, min(1.0, $chance));
    }

    public function rangeInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) floor($this->nextFloat() * (($max - $min) + 1));
    }

    public function counter(): int
    {
        return $this->counter;
    }
}
