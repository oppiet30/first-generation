<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Simulation/PopulationStatistics.php';
require dirname(__DIR__).'/app/Simulation/StatisticsAncestry.php';

use FirstGeneration\Simulation\PopulationStatistics;

// Generate a large result lazily, so this test measures the report's memory
// rather than a test fixture's arrays. No database or production data is used.
final class PopulationCursor extends PDOStatement
{
    private int $position = 0;
    public function __construct(private string $sql, private PopulationDatabase $db) {}
    public function execute(?array $params = null): bool { $this->position = 0; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql,'FROM worlds')) {
            return $this->position++ === 0 ? ['id'=>1,'name'=>'Large world','current_day'=>100000,'status'=>'paused'] : false;
        }
        if (!str_contains($this->sql,'FROM people p') || $this->position >= 2500000) { return false; }
        if ($this->db->buffered) { throw new RuntimeException('Population query must be unbuffered'); }
        $id = ++$this->position;
        return ['id'=>$id,'name'=>'Person '.$id,'sex'=>$id%2 ? 'male' : 'female',
            'birth_day'=>$id <= 2 ? 0 : 1,'initial_age_days'=>$id <= 2 ? 7300 : 0,
            'death_day'=>$id > 2 && $id%10 === 0 ? 50000 : null,
            'mother_id'=>$id <= 2 ? null : 2,'father_id'=>$id <= 2 ? null : 1,
            'cause'=>$id > 2 && $id%10 === 0 ? 'natural' : null];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->sql,'FROM people p')) { throw new RuntimeException('Must not fetchAll population rows'); }
        return [];
    }
    public function closeCursor(): bool { return true; }
}
final class PopulationDatabase extends PDO
{
    public bool $buffered = true;
    public function __construct() {}
    public function exec(string $statement): int|false { return 0; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function inTransaction(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function getAttribute(int $attribute): mixed { return $this->buffered; }
    public function setAttribute(int $attribute, mixed $value): bool { $this->buffered = (bool)$value; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PopulationCursor($query,$this); }
}
function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$db = new PopulationDatabase();
$stream = in_array('--stream',$argv,true);
$report = (new PopulationStatistics($db))->report(1,0,null,!$stream,$stream);
check($db->buffered,'PDO buffering is restored');
check($report['summary']['total_people'] === 2500000,'All people counted');
check($report['summary']['living'] === 2250000 && $report['summary']['deaths'] === 250000,'Exact living and death totals');
check($report['summary']['births'] === 2499998 && $report['summary']['generations'] === 2,'Exact births and generations');
if ($stream) {
    foreach (['daily'=>100001,'people'=>2500000,'deaths'=>250000] as $key=>$expected) {
        $count = 0;
        foreach ($report[$key] as $row) { $count++; }
        check($count === $expected,'Untruncated streamed export: '.$key);
    }
} else {
    check(count($report['daily']) <= 2000 && $report['daily'][0]['day'] === 0 && end($report['daily'])['day'] === 100000,'Bounded chart includes both endpoints');
    check(count($report['deaths']) === 200 && $report['display']['death_rows_total'] === 250000,'Death table bound is explicit');
    check($report['people'] === [],'Dashboard does not retain the people export');
    check(strlen(json_encode($report,JSON_THROW_ON_ERROR)) > 0,'JSON serializes within the memory limit');
}
check(array_sum(array_column($report['yearly'],'deaths')) === 250000,'Yearly totals include every death');
check(array_sum(array_column($report['death_ages'],'count')) === 250000,'Death distribution includes every death');
check(memory_get_peak_usage(true) < 128*1024*1024,'Peak memory below 128 MiB');
echo 'PASS: 2,500,000 people, 100,000 days, exact totals; peak '.round(memory_get_peak_usage(true)/1048576,1)." MiB\n";
