<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\Genealogy;
use FirstGeneration\Simulation\FamilyTreeFormatter;

function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}
$db = Database::connect();
$worldId = null;
try {
    // Committed, isolated fixtures let us exercise the actual CLI in a separate process.
    $db->exec("INSERT INTO worlds(name,seed,rng_state,current_day) VALUES ('Family tree regression',1337,1337,10000)");
    $worldId = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days,death_day,mother_id,father_id) VALUES (?,?,?,?,?,?,?,?)');
    $add = function (string $name, string $sex, int $birth, int $initial, ?int $death, ?int $mother = null, ?int $father = null) use ($db, $insert, $worldId): int {
        $insert->execute([$worldId,$name,$sex,$birth,$initial,$death,$mother,$father]);
        return (int)$db->lastInsertId();
    };
    $father = $add('Adam','male',0,7300,null);
    $mother = $add('Eve','female',0,7300,3546);
    $child = $add('Child-1-1','female',349,0,3546,$mother,$father);
    $infant = $add('Child-1-18','male',7995,0,8070,$mother,$father);
    $legacy = $add('Legacy','female',0,0,0);
    $death = $db->prepare('INSERT INTO deaths(world_id,person_id,day,cause) VALUES (?,?,?,?)');
    $death->execute([$worldId,$mother,3546,'childbirth']);
    $death->execute([$worldId,$child,3546,'natural']);
    $death->execute([$worldId,$infant,8070,'natural']);
    $tree = new Genealogy($db);
    $people = array_column($tree->people($worldId),null,'id');
    same(5,count($people));
    same(17300,$people[$father]['age_days']);
    same(10846,$people[$mother]['age_days']);
    same(3197,$people[$child]['age_days']);
    same(8,$people[$child]['age_years']);
    same(75,$people[$infant]['age_days']);
    same('deceased, age at death 75 days, death day 8070, cause of death: natural',FamilyTreeFormatter::ageAndStatus($people[$infant]));
    same('deceased, age at death 0 days, death day 0, cause of death: unknown',FamilyTreeFormatter::ageAndStatus($people[$legacy]));
    $ancestors = $tree->ancestors($worldId,$child);
    same([$mother,$father],array_column(array_column($ancestors,'person'),'id'));
    same([1,1],array_column($ancestors,'generation'));
    $cli = function (?int $person = null) use ($worldId): string {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/family-tree.php').' '.$worldId;
        if ($person !== null) { $command .= ' '.$person; }
        exec($command,$lines,$status);
        same(0,$status);
        return implode("\n",$lines);
    };
    $output = $cli();
    foreach (["#{$father} Adam (male): age 47 years, living, mother=-, father=-",
        "#{$child} Child-1-1 (female): deceased, age at death 8 years, death day 3546, cause of death: natural, mother={$mother}, father={$father}",
        "#{$infant} Child-1-18 (male): deceased, age at death 75 days, death day 8070, cause of death: natural"] as $line) {
        same(true,str_contains($output,$line));
    }
    same(true,str_contains($cli($child),"1 generation(s) back: Eve (#{$mother}), deceased, age at death 29 years, death day 3546, cause of death: childbirth"));
    // Moving the world clock must never age deceased people.
    $db->exec('UPDATE worlds SET current_day=11000 WHERE id='.$worldId);
    $later = array_column($tree->people($worldId),null,'id');
    same(75,$later[$infant]['age_days']);
    same(18300,$later[$father]['age_days']);
    echo "PASS: family tree ages, causes, legacy deaths, parents and both CLI modes\n";
} finally {
    if ($worldId !== null) {
        $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$worldId]);
    }
}
