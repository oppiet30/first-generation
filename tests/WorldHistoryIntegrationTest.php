<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function history(array $arguments): array {
    $process = proc_open([PHP_BINARY,dirname(__DIR__).'/bin/world-history.php',...$arguments],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start history CLI'); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
$db = Database::connect();
$worlds = [];
try {
    $insert = $db->prepare("INSERT INTO worlds(name,seed,rng_state,current_day,status) VALUES (?,1337,1337,20,'running')");
    foreach (['History regression','History isolation'] as $name) {
        $insert->execute([$name]);
        $worlds[] = (int)$db->lastInsertId();
    }
    [$world,$other] = $worlds;
    $db->prepare("INSERT INTO people(world_id,name,sex,birth_day,death_day) VALUES (?,'Test Person','female',0,10)")->execute([$world]);
    $person = (int)$db->lastInsertId();
    $event = $db->prepare('INSERT INTO world_events(world_id,day,person_id,event_type,details) VALUES (?,?,?,?,?)');
    // Deliberately insert out of chronological order; same-day events retain ID order.
    foreach ([[10,$person,'death','Death: natural'],[0,$person,'founder','Founder created'],
        [5,$person,'birth','Baby born'],[10,null,'death','Legacy death'],
        [1,$person,'partnership','Partnership formed'],[2,$person,'conception','Pregnancy began'],
        [11,null,'extinction','No living people remain']] as [$day,$who,$type,$details]) {
        $event->execute([$world,$day,$who,$type,$details]);
    }
    $event->execute([$other,10,null,'death','OTHER WORLD EVENT']);
    $snapshot = function () use ($db,$world,$person): array {
        return [
            $db->query('SELECT * FROM worlds WHERE id='.$world)->fetch(),
            $db->query('SELECT * FROM people WHERE id='.$person)->fetch(),
            $db->query('SELECT * FROM world_events WHERE world_id='.$world.' ORDER BY id')->fetchAll()
        ];
    };
    $before = $snapshot();
    [$code,$output,$error] = history([(string)$world]);
    check($code === 0 && $error === '','Unfiltered history succeeds');
    $rows = array_values(array_filter(explode("\n",$output),static fn(string $line): bool => str_starts_with($line,'Day ')));
    check(count($rows) === 7,'All fixture events are listed');
    check(array_map(static fn(string $row): int => (int)explode(' ',$row)[1],$rows) === [0,1,2,5,10,10,11],'Events sorted by day then ID');
    check(str_contains($rows[4],'Test Person (#'.$person.') | Death: natural') && str_contains($rows[5],'death | - | Legacy death'),'Person labels and null-person events');
    check(!str_contains($output,'OTHER WORLD EVENT'),'World filter excludes other worlds');
    foreach (['founder','partnership','conception','birth','death','extinction'] as $type) {
        [$code,$output] = history([(string)$world,'--type='.$type]);
        $filtered = array_values(array_filter(explode("\n",$output),static fn(string $line): bool => str_starts_with($line,'Day ')));
        check($code === 0 && count($filtered) === ($type === 'death' ? 2 : 1),'Event type filter: '.$type);
        foreach ($filtered as $row) { check(explode(' | ',$row)[1] === $type,'No other event type leaks'); }
    }
    [$code,$output] = history([(string)$world,'--to=10','--type=death','--from=10']);
    check($code === 0 && substr_count($output,'Day 10 | death') === 2 && !str_contains($output,'Day 11'),'Combined filters include both range boundaries');
    [$code,$output] = history([(string)$world,'--from=11']);
    check($code === 0 && str_contains($output,'Day 11 | extinction') && !str_contains($output,'Day 10'),'Lower bound alone');
    [$code,$output] = history([(string)$world,'--to=0']);
    check($code === 0 && str_contains($output,'Day 0 | founder') && !str_contains($output,'Day 1'),'Upper bound includes day zero');
    [$code,$output] = history([(string)$world,'--from=50','--to=60']);
    check($code === 0 && str_contains($output,'No matching events.'),'Empty results are explicit');
    foreach ([[],['0'],['-1'],['bad'],[(string)$world,'--from=-1'],[(string)$world,'--from=1.5'],
        [(string)$world,'--to=999999999999999999999999'],[(string)$world,'--from=11','--to=10'],
        [(string)$world,'--type=unknown'],[(string)$world,'--type=death','--type=birth'],
        [(string)$world,'--from=0','--from=1'],[(string)$world,'--to='],[(string)$world,'--unexpected=1'],
        [(string)$world,'extra']] as $args) {
        [$code,$out,$err] = history($args);
        check($code === 2 && $out === '' && str_contains($err,'Usage:'),'Invalid arguments rejected: '.json_encode($args));
    }
    [$code,$out,$err] = history(['--help']);
    check($code === 0 && str_contains($out,'Usage:') && $err === '','Help succeeds');
    check($before === $snapshot(),'History must not change world state, people or events');
    $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$other]);
    [$code,$out,$err] = history([(string)$other]);
    check($code === 1 && $out === '' && str_contains($err,'World not found'),'Missing world is an error');
    echo "PASS: history ordering, world isolation, types, inclusive day filters, empty results, argument validation and read-only behavior\n";
} finally {
    foreach ($worlds as $id) { $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$id]); }
}
