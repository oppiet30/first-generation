<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Simulation\DeterministicRandom;

$a = new DeterministicRandom(1337);
$b = new DeterministicRandom(1337);
for ($i = 0; $i < 1000; $i++) {
    if ($a->nextInt(100) !== $b->nextInt(100)) {
        throw new RuntimeException('RNG must be deterministic');
    }
}
if ($a->state() !== $b->state()) { throw new RuntimeException('RNG state mismatch'); }
echo "PASS: deterministic RNG (1,000 draws)\n";
