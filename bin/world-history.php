<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;

$usage = "Usage: php bin/world-history.php <world-id> [--type=founder|partnership|conception|birth|death|extinction] [--from=day] [--to=day]\n"
    . "Days are inclusive and start at zero. Omitted filters include all events.\n";
if ($argc === 2 && $argv[1] === '--help') { echo $usage; exit(0); }
$invalid = static function () use ($usage): never { fwrite(STDERR,$usage); exit(2); };
$worldId = filter_var($argv[1] ?? null,FILTER_VALIDATE_INT);
if ($worldId === false || $worldId === null || $worldId < 1) { $invalid(); }
$options = [];
foreach (array_slice($argv,2) as $argument) {
    if (!preg_match('/^--(type|from|to)=(.+)$/D',$argument,$match) || isset($options[$match[1]])) {
        $invalid();
    }
    $options[$match[1]] = $match[2];
}
$type = $options['type'] ?? null;
if ($type !== null && !in_array($type,['founder','partnership','conception','birth','death','extinction'],true)) {
    $invalid();
}
$days = [];
foreach (['from','to'] as $key) {
    if (isset($options[$key])) {
        $value = filter_var($options[$key],FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) { $invalid(); }
        $days[$key] = $value;
    }
}
if (isset($days['from'],$days['to']) && $days['from'] > $days['to']) { $invalid(); }

$db = Database::connect();
$query = $db->prepare('SELECT id FROM worlds WHERE id=?');
$query->execute([$worldId]);
if ($query->fetchColumn() === false) { fwrite(STDERR,"World not found\n"); exit(1); }
$conditions = ['e.world_id=?'];
$parameters = [$worldId];
if ($type !== null) { $conditions[] = 'e.event_type=?'; $parameters[] = $type; }
if (isset($days['from'])) { $conditions[] = 'e.day>=?'; $parameters[] = $days['from']; }
if (isset($days['to'])) { $conditions[] = 'e.day<=?'; $parameters[] = $days['to']; }
$query = $db->prepare('SELECT e.day,e.event_type,e.person_id,p.name,e.details FROM world_events e '
    . 'LEFT JOIN people p ON p.id=e.person_id AND p.world_id=e.world_id WHERE '
    . implode(' AND ',$conditions).' ORDER BY e.day,e.id');
$query->execute($parameters);
printf("World %d | history | type=%s | days=%s..%s\n",$worldId,$type ?? 'all',$days['from'] ?? 0,$days['to'] ?? 'all');
$count = 0;
while ($event = $query->fetch()) {
    $person = $event['person_id'] === null ? '-'
        : sprintf('%s (#%d)',$event['name'] ?? 'Unknown person',$event['person_id']);
    printf("Day %d | %s | %s | %s\n",$event['day'],$event['event_type'],$person,$event['details']);
    $count++;
}
if ($count === 0) { echo "No matching events.\n"; }
