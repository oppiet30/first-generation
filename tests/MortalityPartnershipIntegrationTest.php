<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\DeterministicRandom;
use FirstGeneration\Simulation\MortalitySystem;
use FirstGeneration\Simulation\ReproductionSystem;

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

$db = Database::connect();
$db->beginTransaction();
try {
    $db->exec("INSERT INTO worlds(name,seed,rng_state,current_day,status) VALUES ('Mortality partnership regression',411,411,300,'running')");
    $world = (int)$db->lastInsertId();
    $person = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days) VALUES (?,?,?,0,7300)');
    $add = function (string $name, string $sex) use ($db,$world,$person): int {
        $person->execute([$world,$name,$sex]);
        return (int)$db->lastInsertId();
    };
    $mother = $add('Mother','female');
    $father = $add('Father','male');
    $replacement = $add('Eligible survivor partner','female');
    $pair = $db->prepare('INSERT INTO partnerships(world_id,mother_id,father_id,started_day) VALUES (?,?,?,1)');
    $pair->execute([$world,$mother,$father]);
    $originalPair = (int)$db->lastInsertId();
    $pregnancy = $db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_day,due_day) VALUES (?,?,?,20,300)');
    $pregnancy->execute([$world,$mother,$father]);
    $pregnancyId = (int)$db->lastInsertId();
    $scalar = function (string $sql, array $args) use ($db): mixed {
        $query = $db->prepare($sql);
        $query->execute($args);
        return $query->fetchColumn();
    };
    // Seed 411 gives a first draw below the 2/1000 childbirth threshold.
    expectSame(true,(new DeterministicRandom(411))->nextInt(1000) < 2,'Fixture triggers maternal mortality');
    $reproduction = new ReproductionSystem($db);
    $reproduction->advance($world,300,new DeterministicRandom(411));
    expectSame(300,(int)$scalar('SELECT death_day FROM people WHERE id=?',[$mother]),'Mother dies on delivery day');
    expectSame('childbirth',$scalar('SELECT cause FROM deaths WHERE person_id=?',[$mother]),'Maternal cause recorded');
    expectSame(1,(int)$scalar("SELECT COUNT(*) FROM world_events WHERE world_id=? AND person_id=? AND event_type='death'",[$world,$mother]),'One maternal death event');
    expectSame(300,(int)$scalar('SELECT ended_day FROM partnerships WHERE id=?',[$originalPair]),'Original partnership closes');
    expectSame(300,(int)$scalar('SELECT delivered_day FROM pregnancies WHERE id=?',[$pregnancyId]),'Delivery recorded');
    $child = (int)$scalar('SELECT child_id FROM pregnancies WHERE id=?',[$pregnancyId]);
    expectSame(true,$child > 0,'Baby is born despite maternal death at delivery');
    expectSame($mother,(int)$scalar('SELECT mother_id FROM people WHERE id=?',[$child]),'Baby retains deceased mother');
    expectSame($father,(int)$scalar('SELECT father_id FROM people WHERE id=?',[$child]),'Baby retains father');
    expectSame(null,$scalar('SELECT death_day FROM people WHERE id=?',[$child]),'Baby survives delivery');
    expectSame(1,(int)$scalar("SELECT COUNT(*) FROM world_events WHERE world_id=? AND person_id=? AND event_type='birth'",[$world,$child]),'One birth event');
    expectSame(1,(int)$scalar('SELECT COUNT(*) FROM partnerships WHERE world_id=? AND mother_id=? AND father_id=? AND ended_day IS NULL',[$world,$replacement,$father]),'Survivor can pair again on scheduled pairing day');
    expectSame(0,(int)$scalar('SELECT COUNT(*) FROM partnerships WHERE world_id=? AND ended_day IS NULL AND (mother_id=? OR father_id=?)',[$world,$mother,$mother]),'Deceased mother cannot re-pair');
    $reproduction->advance($world,301,new DeterministicRandom(411));
    expectSame(1,(int)$scalar('SELECT COUNT(*) FROM people WHERE world_id=? AND mother_id=?',[$world,$mother]),'Delivery cannot repeat');

    // Natural death also closes a partnership; duplicate kills preserve its history.
    $mortality = new MortalitySystem($db);
    $mortality->kill($world,$father,302,'natural');
    $mortality->kill($world,$father,303,'childbirth');
    expectSame('natural',$scalar('SELECT cause FROM deaths WHERE person_id=?',[$father]),'Duplicate kill preserves first cause');
    expectSame(1,(int)$scalar('SELECT COUNT(*) FROM deaths WHERE person_id=?',[$father]),'Duplicate kill creates no second record');
    expectSame(300,(int)$scalar('SELECT ended_day FROM partnerships WHERE id=?',[$originalPair]),'Historical end day is preserved');
    expectSame(302,(int)$scalar('SELECT ended_day FROM partnerships WHERE world_id=? AND mother_id=? AND father_id=?',[$world,$replacement,$father]),'Natural death closes new partnership');
    $newFather = $add('New eligible partner','male');
    $reproduction->advance($world,303,new DeterministicRandom(1337));
    expectSame(0,(int)$scalar('SELECT COUNT(*) FROM partnerships WHERE world_id=? AND ended_day IS NULL',[$world]),'Re-pairing waits for scheduled day');
    $reproduction->advance($world,330,new DeterministicRandom(1337));
    expectSame(1,(int)$scalar('SELECT COUNT(*) FROM partnerships WHERE world_id=? AND mother_id=? AND father_id=? AND ended_day IS NULL',[$world,$replacement,$newFather]),'Surviving woman can pair again');
    expectSame(0,(int)$scalar('SELECT COUNT(*) FROM partnerships a JOIN people m ON m.id=a.mother_id JOIN people f ON f.id=a.father_id WHERE a.world_id=? AND a.ended_day IS NULL AND (m.death_day IS NOT NULL OR f.death_day IS NOT NULL)',[$world]),'All active partners are alive');

    // A mother who died before her due day does not deliver a posthumous baby.
    $pregnancy->execute([$world,$mother,$father]);
    $cancelled = (int)$db->lastInsertId();
    $before = (int)$scalar('SELECT COUNT(*) FROM people WHERE world_id=?',[$world]);
    $reproduction->advance($world,331,new DeterministicRandom(411));
    expectSame(null,$scalar('SELECT child_id FROM pregnancies WHERE id=?',[$cancelled]),'No child for a mother already dead');
    expectSame(331,(int)$scalar('SELECT delivered_day FROM pregnancies WHERE id=?',[$cancelled]),'Dead mother pregnancy is resolved');
    expectSame($before,(int)$scalar('SELECT COUNT(*) FROM people WHERE world_id=?',[$world]),'No posthumous birth');
    echo "PASS: childbirth mortality, surviving baby, partnership closure, re-pairing and dead-mother pregnancy\n";
} finally {
    $db->rollBack();
}
