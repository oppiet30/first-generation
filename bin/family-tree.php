<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\Genealogy;
use FirstGeneration\Simulation\FamilyTreeFormatter;

$worldId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$personId = isset($argv[2]) ? filter_var($argv[2], FILTER_VALIDATE_INT) : null;
if (!$worldId || $worldId < 1 || ($personId !== null && (!$personId || $personId < 1))) {
    fwrite(STDERR, "Usage: php bin/family-tree.php <world-id> [person-id]\n");
    exit(2);
}
$tree = new Genealogy(Database::connect());
if ($personId !== null) {
    foreach ($tree->ancestors($worldId, $personId) as $ancestor) {
        $p = $ancestor['person'];
        printf("%d generation(s) back: %s (#%d), %s\n",
            $ancestor['generation'], $p['name'], $p['id'], FamilyTreeFormatter::ageAndStatus($p));
    }
} else {
    foreach ($tree->people($worldId) as $p) {
        printf("#%d %s (%s): %s, mother=%s, father=%s\n",
            $p['id'], $p['name'], $p['sex'], FamilyTreeFormatter::ageAndStatus($p),
            $p['mother_id'] ?? '-', $p['father_id'] ?? '-');
    }
}
