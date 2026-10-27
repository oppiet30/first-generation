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
        // Discard low LCG bits: their short cycles correlate with the
        // daily conception check and can make every newborn the same sex.
        return (($this->state >> 8) % $maxExclusive);
    }

    public function state(): int { return $this->state; }
}
