<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use FirstGeneration\Infrastructure\Database;
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { fwrite(STDERR,"Usage: php bin/population-report.php <world-id>\n"); exit(2); }
$db = Database::connect();
$q = $db->prepare('SELECT current_day,status FROM worlds WHERE id=?');
$q->execute([$id]); $world = $q->fetch();
if (!$world) { fwrite(STDERR,"World not found\n"); exit(1); }
$q = $db->prepare('SELECT COUNT(*) total, SUM(death_day IS NULL) living, SUM(death_day IS NOT NULL) dead, SUM(mother_id IS NOT NULL) births FROM people WHERE world_id=?');
$q->execute([$id]); $s = $q->fetch();
printf("World %d | day %d | %s\nBirths: %d | Living: %d | Deaths: %d\n",$id,$world['current_day'],$world['status'],(int)$s['births'],(int)$s['living'],(int)$s['dead']);
$q = $db->prepare('SELECT cause,COUNT(*) count FROM deaths WHERE world_id=? GROUP BY cause ORDER BY cause');
$q->execute([$id]);
foreach ($q->fetchAll() as $row) { printf("%s deaths: %d\n",$row['cause'],$row['count']); }
