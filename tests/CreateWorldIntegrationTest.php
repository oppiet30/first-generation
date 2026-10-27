<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\Genealogy;
use FirstGeneration\Simulation\WorldEngine;

function verify(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$db = Database::connect();
$name = 'Create-world regression '.bin2hex(random_bytes(8));
$worldId = null;
try {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/create-world.php').' '.escapeshellarg($name).' 1337';
    exec($command,$lines,$status);
    verify($status === 0,'World creation CLI must succeed after all migrations');
    $query = $db->prepare('SELECT * FROM worlds WHERE name=?');
    $query->execute([$name]);
    $world = $query->fetch();
    verify($world !== false,'CLI must persist its new world');
    $worldId = (int)$world['id'];
    verify((int)$world['current_day'] === 0 && $world['status'] === 'paused','New world must start paused at day zero');
    verify((int)$world['seed'] === 1337 && (int)$world['rng_state'] === 1337,'World creation must preserve the seed');
    $tree = new Genealogy($db);
    $people = $tree->people($worldId);
    verify(count($people) === 2,'New world must have exactly two founders');
    verify(array_column($people,'name') === ['Adam','Eve'],'Founders must be Adam and Eve');
    verify(array_column($people,'sex') === ['male','female'],'Founder sexes must be preserved');
    foreach ($people as $person) {
        verify((int)$person['birth_day'] === 0,'Founder birth day remains zero');
        verify((int)$person['initial_age_days'] === 7300,'Every new founder must start at 20 years');
        verify($person['age_days'] === 7300 && $person['age_years'] === 20,'Genealogy must report adult founder ages immediately');
        verify($person['mother_id'] === null && $person['father_id'] === null && $person['death_day'] === null,'Founders must be living with no parents');
    }
    $events = $db->prepare("SELECT COUNT(*) FROM world_events WHERE world_id=? AND event_type='founder'");
    $events->execute([$worldId]);
    verify((int)$events->fetchColumn() === 2,'Both founder events must be recorded');
    $engine = new WorldEngine($db);
    $pausedRejected = false;
    try { $engine->tick($worldId); } catch (RuntimeException $e) {
        verify($e->getMessage() === 'World not found or not running','Unexpected paused-world error');
        $pausedRejected = true;
    }
    verify($pausedRejected,'Paused worlds must not advance');
    $db->prepare("UPDATE worlds SET status='running' WHERE id=?")->execute([$worldId]);
    $result = $engine->tick($worldId);
    verify($result['day'] === 1 && !$result['extinct'],'Fresh adult founders must survive the seeded first tick');
    foreach ($tree->people($worldId) as $person) {
        verify($person['age_days'] === 7301,'Founder ages must advance from 20 years');
    }
    $pairs = $db->prepare('SELECT mother_id,father_id FROM partnerships WHERE world_id=? AND ended_day IS NULL');
    $pairs->execute([$worldId]);
    $partnerships = $pairs->fetchAll();
    verify(count($partnerships) === 1,'New adult founders must form their first-day partnership');
    verify((int)$partnerships[0]['mother_id'] === (int)$people[1]['id'] && (int)$partnerships[0]['father_id'] === (int)$people[0]['id'],'Partnership must link the new founders');
    echo "PASS: new worlds create adult founders after migrations and start correctly\n";
} finally {
    // Also clean up if creation succeeded but an assertion failed before reading its ID.
    if ($worldId === null) {
        $query = $db->prepare('SELECT id FROM worlds WHERE name=?');
        $query->execute([$name]);
        $found = $query->fetchColumn();
        $worldId = $found === false ? null : (int)$found;
    }
    if ($worldId !== null) {
        $db->prepare('DELETE FROM pregnancies WHERE world_id=?')->execute([$worldId]);
        $db->prepare('DELETE FROM partnerships WHERE world_id=?')->execute([$worldId]);
        $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$worldId]);
    }
}
