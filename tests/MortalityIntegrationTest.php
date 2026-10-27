<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\MortalitySystem;
use FirstGeneration\Simulation\WorldEngine;

$db = Database::connect();
$db->beginTransaction();
try {
    $worldId = (int)$db->query('SELECT id FROM worlds ORDER BY id LIMIT 1')->fetchColumn();
    if ($worldId < 1) { throw new RuntimeException('Missing fixture world'); }
    $mortality = new MortalitySystem($db);
    $people = $db->prepare('SELECT id FROM people WHERE world_id=? AND death_day IS NULL ORDER BY id');
    $people->execute([$worldId]);
    $ids = array_map('intval', $people->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) !== 2) { throw new RuntimeException('Expected two living founders'); }

    $mortality->kill($worldId, $ids[0], 0, 'test');
    $mortality->kill($worldId, $ids[0], 0, 'test');
    $count = $db->prepare('SELECT COUNT(*) FROM deaths WHERE world_id=? AND person_id=?');
    $count->execute([$worldId, $ids[0]]);
    if ((int)$count->fetchColumn() !== 1) { throw new RuntimeException('Death must be idempotent'); }
    if ($mortality->completeIfExtinct($worldId, 0)) {
        throw new RuntimeException('World must not end with a survivor');
    }

    $mortality->kill($worldId, $ids[1], 0, 'test');
    if (!$mortality->completeIfExtinct($worldId, 0)) {
        throw new RuntimeException('World should become extinct');
    }
    $status = $db->prepare('SELECT status FROM worlds WHERE id=?');
    $status->execute([$worldId]);
    if ($status->fetchColumn() !== 'completed') {
        throw new RuntimeException('World must be completed');
    }
    $events = $db->prepare("SELECT COUNT(*) FROM world_events WHERE world_id=? AND event_type='extinction'");
    $events->execute([$worldId]);
    if ((int)$events->fetchColumn() !== 1) {
        throw new RuntimeException('Expected one extinction event');
    }
    echo "PASS: death idempotency, survivor protection and extinction\n";
} finally {
    $db->rollBack();
}
