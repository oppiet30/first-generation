<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use InvalidArgumentException;

final class DeterministicRandom
{
    private int $state;

    public function __construct(int $seed) { $this->state = $seed & 0x7fffffff; }

    public function nextInt(int $maxExclusive): int
    {
        if ($maxExclusive <= 0) { throw new InvalidArgumentException('Maximum must be positive'); }
        $this->state = (int)(($this->state * 1103515245 + 12345) & 0x7fffffff);
        return $this->state % $maxExclusive;
    }

    public function state(): int { return $this->state; }
}
