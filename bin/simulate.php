<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\WorldEngine;

$worldId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$ticks = filter_var($argv[2] ?? '1', FILTER_VALIDATE_INT);
if (!$worldId || $worldId < 1 || !$ticks || $ticks < 1 || $ticks > 10000) {
    fwrite(STDERR, "Usage: php bin/simulate.php <world-id> [days: 1..10000]\n");
    exit(2);
}
$engine = new WorldEngine(Database::connect());
for ($i = 0; $i < $ticks; $i++) { $result = $engine->tick($worldId); }
printf("World %d: day %d (year %d, day %d)\n",
    $worldId, $result['day'], $result['year'], $result['day_of_year']);
