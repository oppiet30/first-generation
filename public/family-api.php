<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use FirstGeneration\Infrastructure\Database;
use FirstGeneration\Simulation\FamilyTreeView;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function familyFail(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['error'=>$message],JSON_THROW_ON_ERROR);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); familyFail(405,'Use GET to read the family tree.'); }
$values = [];
foreach (['world','person','after'] as $key) {
    if (!isset($_GET[$key])) { continue; }
    $value = is_string($_GET[$key]) ? filter_var($_GET[$key],FILTER_VALIDATE_INT) : false;
    if ($value === false || $value < ($key === 'after' ? 0 : 1)) { familyFail(400,'Invalid world or person ID.'); }
    $values[$key] = $value;
}
$search = $_GET['search'] ?? '';
if (!is_string($search) || strlen($search)>100) { familyFail(400,'Search must be at most 100 bytes.'); }
$search = trim($search);
try {
    $result = (new FamilyTreeView(Database::connect()))->read($values['world'] ?? 1,$values['person'] ?? null,$search,$values['after'] ?? 0);
    echo json_encode($result,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (RuntimeException $error) {
    if (in_array($error->getMessage(),['World not found','Person not found in world'],true)) { familyFail(404,$error->getMessage()); }
    error_log((string)$error);
    familyFail(500,'Family tree could not be loaded. Check the server log.');
} catch (Throwable $error) {
    error_log((string)$error);
    familyFail(500,'Family tree could not be loaded. Check the server log.');
}
