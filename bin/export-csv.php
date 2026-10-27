<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;

$usage = "Usage: php bin/export-csv.php <world-id> [--report=daily|people] [--from=day] [--to=day]\n"
    . "Default: daily population rows from day 0 through the current day. Inclusive bounds apply only to daily reports.\n";
if ($argc === 2 && $argv[1] === '--help') { echo $usage; exit(0); }
$invalid = static function () use ($usage): never { fwrite(STDERR,$usage); exit(2); };
$worldId = filter_var($argv[1] ?? null,FILTER_VALIDATE_INT);
if (!$worldId || $worldId < 1) { $invalid(); }
$options = [];
foreach (array_slice($argv,2) as $argument) {
    if (!preg_match('/^--(report|from|to)=(.+)$/D',$argument,$match) || isset($options[$match[1]])) { $invalid(); }
    $options[$match[1]] = $match[2];
}
$report = $options['report'] ?? 'daily';
if (!in_array($report,['daily','people'],true)) { $invalid(); }
$bounds = [];
foreach (['from','to'] as $key) {
    if (isset($options[$key])) {
        $value = filter_var($options[$key],FILTER_VALIDATE_INT);
        if ($report !== 'daily' || $value === false || $value < 0) { $invalid(); }
        $bounds[$key] = $value;
    }
}
if (isset($bounds['from'],$bounds['to']) && $bounds['from'] > $bounds['to']) { $invalid(); }
$db = Database::connect();
// Read a consistent snapshot even if another connection is advancing the world.
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->beginTransaction();
try {
    $query = $db->prepare('SELECT current_day FROM worlds WHERE id=?');
    $query->execute([$worldId]);
    $current = $query->fetchColumn();
    if ($current === false) { $db->rollBack(); fwrite(STDERR,"World not found\n"); exit(1); }
    $current = (int)$current;
    if (($bounds['from'] ?? 0) > $current || ($bounds['to'] ?? $current) > $current) {
        $db->rollBack(); fwrite(STDERR,"Day range exceeds current world day\n"); exit(2);
    }
    $query = $db->prepare('SELECT p.id,p.name,p.sex,p.birth_day,p.initial_age_days,p.death_day,p.mother_id,p.father_id,d.cause FROM people p LEFT JOIN deaths d ON d.person_id=p.id AND d.world_id=p.world_id WHERE p.world_id=? ORDER BY p.id');
    $query->execute([$worldId]);
    $people = $query->fetchAll();
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) { $db->rollBack(); }
    throw $error;
}
// Explicit empty escape argument produces standard CSV with no PHP-version-dependent escaping.
$write = static function (array $row): void {
    if (fputcsv(STDOUT,$row,',','"','') === false) { throw new RuntimeException('Cannot write CSV'); }
};
if ($report === 'people') {
    $write(['world_id','person_id','name','sex','birth_day','initial_age_days','death_day','death_cause','age_days','age_years','mother_id','father_id']);
    foreach ($people as $person) {
        $age = max(0,($person['death_day'] === null ? $current : (int)$person['death_day']) - (int)$person['birth_day'] + (int)$person['initial_age_days']);
        $name = $person['name'];
        // Keep arbitrary person names literal when opened in spreadsheet software.
        if (preg_match('/^[=+@\-\t\r\n]/',$name)) { $name = "'".$name; }
        $write([$worldId,$person['id'],$name,$person['sex'],$person['birth_day'],$person['initial_age_days'],
            $person['death_day'],$person['death_day'] === null ? '' : ($person['cause'] ?? 'unknown'),
            $age,intdiv($age,365),$person['mother_id'],$person['father_id']]);
    }
    exit(0);
}
$changes = [];
foreach ($people as $person) {
    $day = (int)$person['birth_day'];
    $changes[$day]['added'] = ($changes[$day]['added'] ?? 0) + 1;
    if ($person['mother_id'] !== null) { $changes[$day]['births'] = ($changes[$day]['births'] ?? 0) + 1; }
    if ($person['death_day'] !== null) {
        $day = (int)$person['death_day'];
        $changes[$day]['deaths'] = ($changes[$day]['deaths'] ?? 0) + 1;
        $cause = in_array($person['cause'],['natural','childbirth'],true) ? $person['cause'] : 'other';
        $changes[$day][$cause] = ($changes[$day][$cause] ?? 0) + 1;
    }
}
$write(['world_id','day','year','day_of_year','total_people','living','births','deaths','cumulative_births','cumulative_deaths','natural_deaths','childbirth_deaths','other_deaths']);
$total = $living = $births = $deaths = 0;
for ($day = 0, $end = $bounds['to'] ?? $current; $day <= $end; $day++) {
    $change = $changes[$day] ?? [];
    $newBirths = $change['births'] ?? 0;
    $newDeaths = $change['deaths'] ?? 0;
    $total += $change['added'] ?? 0;
    $living += ($change['added'] ?? 0) - $newDeaths;
    $births += $newBirths;
    $deaths += $newDeaths;
    if ($day < ($bounds['from'] ?? 0)) { continue; }
    $write([$worldId,$day,$day === 0 ? 0 : intdiv($day-1,365)+1,$day === 0 ? 0 : ($day-1)%365+1,
        $total,$living,$newBirths,$newDeaths,$births,$deaths,$change['natural'] ?? 0,$change['childbirth'] ?? 0,$change['other'] ?? 0]);
}
