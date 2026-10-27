<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\PopulationStatistics;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function fail(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$message],JSON_THROW_ON_ERROR);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); fail(405,'Use GET to read statistics.'); }
$values = [];
foreach (['world','from','to'] as $key) {
    if (!isset($_GET[$key])) { continue; }
    if (!is_string($_GET[$key])) { fail(400,'Invalid day or world ID.'); }
    $value = filter_var($_GET[$key],FILTER_VALIDATE_INT);
    if ($value === false || $value < ($key === 'world' ? 1 : 0)) { fail(400,'Invalid day or world ID.'); }
    $values[$key] = $value;
}
$format = $_GET['format'] ?? 'json';
$reportName = $_GET['report'] ?? 'daily';
$columns = [
    'daily'=>['world_id','day','year','day_of_year','total_people','living','births','deaths','cumulative_births','cumulative_deaths','natural_deaths','childbirth_deaths','other_deaths'],
    'people'=>['world_id','person_id','name','sex','birth_day','initial_age_days','death_day','death_cause','age_days','age_years','mother_id','father_id','generation'],
    'yearly'=>['year','from_day','to_day','births','deaths'],
    'ages'=>['band','male','female'],'death_ages'=>['band','count'],'infants'=>['band','count'],
    'generations'=>['generation','living','deceased'],'deaths'=>['person_id','name','day','age_days','age_years','cause']
];
if (!is_string($format) || !in_array($format,['json','csv'],true) || !is_string($reportName) || !isset($columns[$reportName])) {
    fail(400,'Unknown report or format.');
}
try {
    $report = (new PopulationStatistics(Database::connect()))->report($values['world'] ?? 1,$values['from'] ?? 0,$values['to'] ?? null);
} catch (InvalidArgumentException $error) {
    fail(400,$error->getMessage());
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'World not found') { fail(404,'World not found.'); }
    error_log((string)$error);
    fail(500,'Statistics could not be loaded. Check the server log.');
} catch (Throwable $error) {
    error_log((string)$error);
    fail(500,'Statistics could not be loaded. Check the server log.');
}
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="world-'.$report['world']['id'].'-'.$reportName.'-'.$report['from'].'-'.$report['to'].'.csv"');
$output = fopen('php://output','w');
fputcsv($output,$columns[$reportName],',','"','');
foreach ($report[$reportName] as $row) {
    $line = [];
    foreach ($columns[$reportName] as $column) {
        $value = $row[$column];
        if (is_string($value) && preg_match('/^[=+@\-\t\r\n]/',$value)) { $value = "'".$value; }
        $line[] = $value;
    }
    fputcsv($output,$line,',','"','');
}
fclose($output);
