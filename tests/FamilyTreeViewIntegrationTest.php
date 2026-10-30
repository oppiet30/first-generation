<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\FamilyTreeView;

function familyCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$db = Database::connect();
$world = null;
try {
    $db->exec("INSERT INTO worlds(name,seed,rng_state,current_day) VALUES ('Paged family',1,1,1000)");
    $world = (int)$db->lastInsertId();
    $q = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days,mother_id,father_id) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$world,'Father','male',0,7300,null,null]); $father=(int)$db->lastInsertId();
    $q->execute([$world,'Mother','female',0,7300,null,null]); $mother=(int)$db->lastInsertId();
    $ids=[];
    for($i=0;$i<25;$i++) { $q->execute([$world,'Child_'.$i,'female',$i,0,$mother,$father]); $ids[]=(int)$db->lastInsertId(); }
    $view = new FamilyTreeView($db);
    $first=$view->read($world,$father);
    familyCheck(count($first['children'])===20 && $first['next_after']===$ids[19],'First child page has a stable cursor');
    $last=$view->read($world,$father,'',$first['next_after']);
    familyCheck(count($last['children'])===5 && $last['next_after']===null,'Remaining children are accessible');
    familyCheck(array_column(array_merge($first['children'],$last['children']),'id')===$ids,'Pagination has no gaps or duplicates');
    familyCheck(count($view->read($world,$mother)['children'])===20,'Mother and father both find their children');
    familyCheck($view->read($world)['person']['id']===$father,'Default selection is the first person in that world');
    familyCheck($view->read($world,$ids[0])['person']['age_years']===2,'Living age uses world day');
    familyCheck(count($view->read($world,null,'Child_')['matches'])===20 && $view->read($world,null,'Child_')['more'],'Search results are bounded');
    familyCheck($view->read($world,null,'Child%')['matches']===[],'Percent characters are literal');
    familyCheck($view->read($world,$father)['parents']===[],'Founders have no parents');
    echo "PASS: indexed family neighborhoods, child pagination, bounded prefix search, ages and founders\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    if ($world !== null) { $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$world]); }
}
