<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use FirstGeneration\Infrastructure\Database;

function insist(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function exportRows(array $args): array {
    $process = proc_open([PHP_BINARY,dirname(__DIR__).'/bin/export-csv.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    insist(is_resource($process),'Start export CLI');
    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    $stream = fopen('php://memory','r+');
    fwrite($stream,$text); rewind($stream);
    $header = fgetcsv($stream,0,',','"','');
    $rows = [];
    while (($row = fgetcsv($stream,0,',','"','')) !== false) {
        insist(count($row) === count($header),'Consistent CSV columns');
        $rows[] = array_combine($header,$row);
    }
    fclose($stream);
    return [$code,$rows,$error,$text];
}
$db = Database::connect();
$worlds = [];
try {
    foreach (['CSV regression','CSV empty world'] as $name) {
        $db->prepare('INSERT INTO worlds(name,seed,rng_state,current_day) VALUES (?,1337,1337,366)')->execute([$name]);
        $worlds[] = (int)$db->lastInsertId();
    }
    [$world,$empty] = $worlds;
    $insert = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days,death_day,mother_id,father_id) VALUES (?,?,?,?,?,?,?,?)');
    $insert->execute([$world,'Adam','male',0,7300,2,null,null]); $father=(int)$db->lastInsertId();
    $insert->execute([$world,'Eve','female',0,7300,null,null,null]); $mother=(int)$db->lastInsertId();
    $insert->execute([$world,'Legacy','male',0,3650,3,null,null]);
    $quotedName = "Child, \"quoted\"\nname";
    $insert->execute([$world,$quotedName,'female',1,0,2,$mother,$father]); $infant=(int)$db->lastInsertId();
    $insert->execute([$world,'=literal name','female',2,0,2,$mother,$father]); $sameDay=(int)$db->lastInsertId();
    $death = $db->prepare('INSERT INTO deaths(world_id,person_id,day,cause) VALUES (?,?,?,?)');
    foreach ([[$father,2,'natural'],[$infant,2,'natural'],[$sameDay,2,'childbirth']] as $entry) {
        $death->execute([$world,...$entry]);
    }
    $snapshot = function () use ($db,$world): array {
        return [$db->query('SELECT * FROM worlds WHERE id='.$world)->fetch(),
            $db->query('SELECT * FROM people WHERE world_id='.$world.' ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM deaths WHERE world_id='.$world.' ORDER BY id')->fetchAll()];
    };
    $before = $snapshot();
    [$code,$rows,$err] = exportRows([(string)$world]);
    insist($code === 0 && $err === '' && count($rows) === 367,'One CSV row per day including day zero');
    insist($rows[0]['total_people'] === '3' && $rows[0]['living'] === '3' && $rows[0]['births'] === '0','Founders are not births');
    insist($rows[1]['births'] === '1' && $rows[1]['living'] === '4','First birth counted');
    insist($rows[2]['births'] === '1' && $rows[2]['deaths'] === '3' && $rows[2]['living'] === '2','Births and deaths on same day');
    insist($rows[2]['natural_deaths'] === '2' && $rows[2]['childbirth_deaths'] === '1','Daily causes split correctly');
    insist($rows[3]['other_deaths'] === '1' && $rows[3]['living'] === '1','Legacy death without cause is counted');
    insist($rows[365]['year'] === '1' && $rows[365]['day_of_year'] === '365' && $rows[366]['year'] === '2' && $rows[366]['day_of_year'] === '1','Calendar matches simulation ticks');
    insist($rows[366]['cumulative_births'] === '2' && $rows[366]['cumulative_deaths'] === '4' && $rows[366]['total_people'] === '5','Final cumulative totals');
    foreach ($rows as $row) {
        insist((int)$row['total_people'] - (int)$row['cumulative_deaths'] === (int)$row['living'],'Population reconciles every day');
        insist((int)$row['natural_deaths']+(int)$row['childbirth_deaths']+(int)$row['other_deaths'] === (int)$row['deaths'],'Causes reconcile every day');
    }
    [$code,$slice] = exportRows([(string)$world,'--from=2','--to=3']);
    insist($code === 0 && $slice === array_slice($rows,2,2),'Inclusive slice preserves full-history cumulative totals');
    [$code,$end] = exportRows([(string)$world,'--from=366']);
    insist($code === 0 && $end === [$rows[366]],'Lower bound alone');
    [$code,$start] = exportRows([(string)$world,'--to=0']);
    insist($code === 0 && $start === [$rows[0]],'Day-zero upper bound');
    [$code,$people] = exportRows([(string)$world,'--report=people']);
    $people = array_column($people,null,'person_id');
    insist($code === 0 && count($people) === 5,'People export stays within selected world');
    insist($people[$infant]['name'] === $quotedName && $people[$infant]['age_days'] === '1' && $people[$infant]['age_years'] === '0','CSV quotes and exact infant age round-trip');
    insist($people[$father]['age_days'] === '7302' && $people[$father]['death_cause'] === 'natural','Founder death age includes initial age');
    insist($people[$mother]['age_days'] === '7666' && $people[$mother]['death_day'] === '' && $people[$mother]['death_cause'] === '','Living age and blank death fields');
    insist($people[$sameDay]['age_days'] === '0' && $people[$sameDay]['mother_id'] === (string)$mother,'Same-day infant death and parent links');
    insist($people[$sameDay]['name'] === "'=literal name",'Formula-like name stays literal in a spreadsheet');
    [$code,$emptyRows] = exportRows([(string)$empty,'--to=0']);
    insist($code === 0 && $emptyRows[0]['living'] === '0','Empty worlds produce zero population');
    [$code,$emptyPeople] = exportRows([(string)$empty,'--report=people']);
    insist($code === 0 && $emptyPeople === [],'Empty people export has only header');
    foreach ([[],['0'],['bad'],[(string)$world,'--report=bad'],[(string)$world,'--from=-1'],
        [(string)$world,'--from=3','--to=2'],[(string)$world,'--to=367'],[(string)$world,'--from=367'],
        [(string)$world,'--report=people','--to=3'],[(string)$world,'--to=0','--to=1'],
        [(string)$world,'--to=1.5'],[(string)$world,'--to=999999999999999999999'],[(string)$world,'--bad=1']] as $args) {
        [$code,$unused,$err,$out] = exportRows($args);
        insist($code === 2 && $err !== '' && $out === '','Invalid CSV arguments produce no CSV: '.json_encode($args));
    }
    insist($before === $snapshot(),'Export must not mutate world, people or deaths');
    $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$empty]);
    [$code,$unused,$err,$out] = exportRows([(string)$empty]);
    insist($code === 1 && str_contains($err,'World not found') && $out === '','Missing world yields no CSV');
    echo "PASS: daily CSV reconstruction, ranges, totals, death causes, exact ages, CSV escaping, validation and read-only exports\n";
} finally {
    foreach ($worlds as $id) { $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$id]); }
}
