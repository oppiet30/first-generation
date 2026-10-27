<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;

$name = trim($argv[1] ?? 'First World');
$seed = filter_var($argv[2] ?? '1337', FILTER_VALIDATE_INT);
if ($name === '' || strlen($name) > 100 || $seed === false || $seed < 0 || $seed > 2147483647) {
    fwrite(STDERR, "Usage: php bin/create-world.php [name] [seed: 0..2147483647]\n");
    exit(2);
}
$db = Database::connect();
$db->beginTransaction();
try {
    $q = $db->prepare("INSERT INTO worlds(name,seed,rng_state,status) VALUES (?,?,?,'paused')");
    $q->execute([$name, $seed, $seed]);
    $worldId = (int)$db->lastInsertId();
    $person = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days) VALUES (?,?,?,0,7300)');
    $event = $db->prepare('INSERT INTO world_events(world_id,day,person_id,event_type,details) VALUES (?,0,?,?,?)');
    foreach ([['Adam','male'],['Eve','female']] as [$personName, $sex]) {
        $person->execute([$worldId, $personName, $sex]);
        $event->execute([$worldId, (int)$db->lastInsertId(), 'founder', "$personName created as an adult founder"]);
    }
    $db->commit();
    echo "Created world $worldId with Adam and Eve (paused at day 0).\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    throw $e;
}
