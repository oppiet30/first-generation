<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\PopulationStatistics;

function demand(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$db = Database::connect();
$worlds = [];
$server = null;
$log = tempnam(sys_get_temp_dir(),'fg-dashboard-');
try {
    foreach (['Dashboard regression','Empty dashboard'] as $name) {
        $db->prepare("INSERT INTO worlds(name,seed,rng_state,current_day) VALUES (?,1337,1337,366)")->execute([$name]);
        $worlds[] = (int)$db->lastInsertId();
    }
    [$world,$empty] = $worlds;
    $insert = $db->prepare('INSERT INTO people(world_id,name,sex,birth_day,initial_age_days,death_day,mother_id,father_id) VALUES (?,?,?,?,?,?,?,?)');
    $insert->execute([$world,'Adam','male',0,7300,2,null,null]); $father=(int)$db->lastInsertId();
    $insert->execute([$world,'Eve','female',0,7300,null,null,null]); $mother=(int)$db->lastInsertId();
    $insert->execute([$world,'<script>literal</script>','female',1,0,3,$mother,$father]); $child=(int)$db->lastInsertId();
    $insert->execute([$world,'Infant','male',2,0,2,$mother,$father]); $infant=(int)$db->lastInsertId();
    $insert->execute([$world,'Grandchild','male',3,0,null,$child,$father]);
    $death = $db->prepare('INSERT INTO deaths(world_id,person_id,day,cause) VALUES (?,?,?,?)');
    $death->execute([$world,$father,2,'natural']); $death->execute([$world,$infant,2,'natural']);
    $event = $db->prepare("INSERT INTO world_events(world_id,day,person_id,event_type,details) VALUES (?,?,?,'death',?)");
    $event->execute([$world,2,$father,'Death: natural']);
    $event->execute([$empty,2,null,'OTHER WORLD EVENT']);
    $snapshot = function () use ($db,$world): array {
        return [$db->query('SELECT * FROM worlds WHERE id='.$world)->fetch(),
            $db->query('SELECT * FROM people WHERE world_id='.$world.' ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM deaths WHERE world_id='.$world.' ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM world_events WHERE world_id='.$world.' ORDER BY id')->fetchAll()];
    };
    $before = $snapshot();
    $stats = new PopulationStatistics($db);
    $full = $stats->report($world);
    demand($full['summary'] === ['living'=>2,'births'=>3,'deaths'=>3,'total_people'=>5,'generations'=>3,'range_births'=>3,'range_deaths'=>3],'Reconciled full-world totals');
    demand(count($full['daily']) === 367 && $full['daily'][366]['year'] === 2 && $full['daily'][366]['day_of_year'] === 1,'Daily calendar includes day zero');
    demand($full['generations'] === [['generation'=>1,'living'=>1,'deceased'=>1],['generation'=>2,'living'=>0,'deceased'=>2],['generation'=>3,'living'=>1,'deceased'=>0]],'Mixed parent generations use greater depth');
    demand(array_sum(array_column($full['death_ages'],'count')) === 3 && array_sum(array_column($full['infants'],'count')) === 2,'Death distributions reconcile');
    demand(array_sum(array_column($full['ages'],'male')) === 1 && array_sum(array_column($full['ages'],'female')) === 1,'Living sex and age counts');
    $early = $stats->report($world,0,1);
    demand($early['summary']['living'] === 3 && $early['summary']['deaths'] === 0 && $early['summary']['births'] === 1,'Historical end excludes future births and deaths');
    demand(count($early['people']) === 3 && $early['people'][0]['death_day'] === null && $early['people'][0]['age_days'] === 7301,'Historical people age as of selected day');
    demand($early['summary']['generations'] === 2 && $early['deaths'] === [] && $early['events'] === [],'Future ancestry and events do not leak');
    $slice = $stats->report($world,2,3);
    demand($slice['daily'] === array_slice($full['daily'],2,2),'Range does not reset cumulative counts');
    demand(array_sum(array_column($slice['yearly'],'births')) === 2 && array_sum(array_column($slice['yearly'],'deaths')) === 3,'Yearly graphs respect selected interval');
    demand($slice['deaths'][1]['age_days'] === 2 && $slice['deaths'][1]['cause'] === 'unknown','Exact infant and legacy cause records');
    demand($stats->report($empty)['summary']['living'] === 0,'Empty population works');
    foreach ([[-1,1],[3,2],[0,367]] as [$from,$to]) {
        $rejected = false;
        try { $stats->report($world,$from,$to); } catch (InvalidArgumentException) { $rejected = true; }
        demand($rejected,'Invalid ranges rejected');
    }
    // Compare the dashboard daily series with the existing CLI export.
    $process = proc_open([PHP_BINARY,dirname(__DIR__).'/bin/export-csv.php',(string)$world],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $csv=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    demand(proc_close($process) === 0 && $err === '','Existing CSV CLI succeeds');
    $lines=explode("\n",trim($csv)); $header=str_getcsv(array_shift($lines),',','"','');
    foreach ($lines as $day=>$line) {
        $row=array_combine($header,str_getcsv($line,',','"',''));
        foreach ($row as $key=>$value) { demand((int)$value === $full['daily'][$day][$key],'Dashboard matches CLI '.$day.' '.$key); }
    }
    // Exercise actual HTTP status codes, CSV downloads and document-root boundaries.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    demand($socket !== false,'Reserve a local test port');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$serverPipes);
    demand(is_resource($server),'Start dashboard HTTP test server'); fclose($serverPipes[0]);
    $ready=false;
    for($attempt=0;$attempt<50;$attempt++) {
        $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
        if($probe){fclose($probe);$ready=true;break;} usleep(100000);
    }
    demand($ready,'HTTP server becomes ready');
    $get=function(string $path,string $method='GET') use($address):array {
        $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>10,'method'=>$method]]);
        $body=file_get_contents('http://'.$address.'/'.$path,false,$context);
        demand($body !== false,'HTTP request succeeds');
        preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);
        return [(int)$match[1],$body,$http_response_header];
    };
    [$code,$body]=$get('api.php?world='.$world.'&from=2&to=3');
    demand($code === 200 && json_decode($body,true,512,JSON_THROW_ON_ERROR)['summary']['range_births'] === 2,'JSON API serves selected range');
    foreach(['daily','people','yearly','ages','death_ages','infants','generations','deaths'] as $report) {
        [$code,$body,$headers]=$get('api.php?world='.$world.'&format=csv&report='.$report);
        demand($code === 200 && strlen($body)>10 && str_contains(implode(' ',$headers),'attachment; filename='),'CSV download '.$report);
    }
    foreach(['world=0','world[]=1','world=bad','world='.$world.'&to=367','world='.$world.'&from=4&to=3','format=bad','report=bad','format[]=json'] as $query) {
        [$code,$body]=$get('api.php?'.$query);
        demand($code === 400 && isset(json_decode($body,true)['error']),'Invalid HTTP input '.$query);
    }
    [$code]=$get('api.php?world='.$world,'POST'); demand($code === 405,'Write methods rejected');
    $db->prepare('DELETE FROM worlds WHERE id=?')->execute([$empty]);
    [$code]=$get('api.php?world='.$empty); demand($code === 404,'Missing HTTP world');
    foreach(['.env','vendor/autoload.php','database/migrations/001_initial.sql'] as $path) {
        [$code]=$get($path); demand($code === 404,'Private files outside public document root: '.$path);
    }
    [$code,$body]=$get(''); demand($code === 200 && str_contains($body,'Population through time'),'Dashboard page serves');
    demand($before === $snapshot(),'Dashboard and downloads leave simulation records unchanged');
    echo "PASS: statistics totals, historical cutoffs, generations, CSV parity, HTTP downloads, validation and read-only behavior\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_file($log)){unlink($log);}
    foreach($worlds as $id){$db->prepare('DELETE FROM worlds WHERE id=?')->execute([$id]);}
}
