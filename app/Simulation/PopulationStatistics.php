<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PopulationStatistics
{
    public function __construct(private PDO $db) {}

    /** Dashboard mode bounds display rows; stream mode returns disk-backed CSV iterables. */
    public function report(int $worldId, int $from = 0, ?int $to = null, bool $dashboard = false, bool $stream = false): array
    {
        $spool = static function () {
            $file = tmpfile();
            if ($file === false) { throw new RuntimeException('Cannot create statistics spool'); }
            return $file;
        };
        $append = static function (&$target, array $row): void {
            if (is_array($target)) { $target[] = $row; return; }
            $line = json_encode($row,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
            if (fwrite($target,$line) !== strlen($line)) { throw new RuntimeException('Cannot write statistics spool'); }
        };
        $read = static function ($file): \Generator {
            rewind($file);
            while (($line = fgets($file)) !== false) { yield json_decode($line,true,512,JSON_THROW_ON_ERROR); }
            if (!feof($file)) { throw new RuntimeException('Cannot read statistics spool'); }
        };
        $result = static function ($value) use ($read): iterable {
            if (is_array($value)) { return $value; }
            return (static function () use ($value,$read): \Generator {
                try { yield from $read($value); } finally { fclose($value); }
            })();
        };
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id,name,current_day,status FROM worlds WHERE id=?');
            $q->execute([$worldId]);
            $world = $q->fetch();
            if (!$world) { throw new RuntimeException('World not found'); }
            $to ??= (int)$world['current_day'];
            if ($from < 0 || $from > $to || $to > (int)$world['current_day']) {
                throw new InvalidArgumentException('Choose days between 0 and the current world day, with From no later than To.');
            }
            // Count changes while streaming, then spool for the remaining pass without retaining every
            // person (or PDO's buffered result) in PHP memory.
            $changes = [];
            $ancestry = new StatisticsAncestry();
            $people = $spool();
            $buffered = $this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $q = $this->db->prepare('SELECT p.id,p.name,p.sex,p.birth_day,p.initial_age_days,p.death_day,p.mother_id,p.father_id,d.cause FROM people p LEFT JOIN deaths d ON d.person_id=p.id AND d.world_id=p.world_id WHERE p.world_id=? ORDER BY p.id');
                $q->execute([$worldId]);
                while ($p = $q->fetch()) {
                    $append($people,$p);
                    $ancestry->add((int)$p['id'],(int)$p['mother_id'],(int)$p['father_id']);
                    $day = (int)$p['birth_day'];
                    $changes[$day]['added'] = ($changes[$day]['added'] ?? 0) + 1;
                    if ($p['mother_id'] !== null) { $changes[$day]['births'] = ($changes[$day]['births'] ?? 0) + 1; }
                    if ($p['death_day'] !== null) {
                        $day = (int)$p['death_day'];
                        $changes[$day]['deaths'] = ($changes[$day]['deaths'] ?? 0) + 1;
                        $cause = in_array($p['cause'],['natural','childbirth'],true) ? $p['cause'] : 'other';
                        $changes[$day][$cause] = ($changes[$day][$cause] ?? 0) + 1;
                    }
                }
            } finally {
                $q->closeCursor();
                $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
            }
            $q = $this->db->prepare('SELECT e.day,e.event_type,e.details,e.person_id,p.name FROM world_events e LEFT JOIN people p ON p.id=e.person_id AND p.world_id=e.world_id WHERE e.world_id=? AND e.day>=? AND e.day<=? ORDER BY e.day DESC,e.id DESC LIMIT 20');
            $q->execute([$worldId,$from,$to]);
            $events = $q->fetchAll();
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
        $world['id'] = (int)$world['id'];
        $world['current_day'] = (int)$world['current_day'];
        $rows = static fn(): \Generator => $read($people);
        $daily = $stream ? $spool() : [];
        $yearly = [];
        $dailyStep = $dashboard ? max(1,(int)ceil(($to-$from)/1999)) : 1;
        $total = $living = $births = $deaths = $rangeBirths = $rangeDeaths = 0;
        for ($day = 0; $day <= $to; $day++) {
            $c = $changes[$day] ?? [];
            $total += $c['added'] ?? 0;
            $living += ($c['added'] ?? 0) - ($c['deaths'] ?? 0);
            $births += $c['births'] ?? 0;
            $deaths += $c['deaths'] ?? 0;
            if ($day < $from) { continue; }
            $year = $day === 0 ? 0 : intdiv($day-1,365)+1;
            if (($day-$from)%$dailyStep === 0 || $day === $to) $append($daily,['world_id'=>$worldId,'day'=>$day,'year'=>$year,'day_of_year'=>$day === 0 ? 0 : ($day-1)%365+1,
                'total_people'=>$total,'living'=>$living,'births'=>$c['births'] ?? 0,'deaths'=>$c['deaths'] ?? 0,
                'cumulative_births'=>$births,'cumulative_deaths'=>$deaths,'natural_deaths'=>$c['natural'] ?? 0,
                'childbirth_deaths'=>$c['childbirth'] ?? 0,'other_deaths'=>$c['other'] ?? 0]);
            $yearly[$year] ??= ['year'=>$year,'from_day'=>$day,'to_day'=>$day,'births'=>0,'deaths'=>0];
            $yearly[$year]['to_day'] = $day;
            $yearly[$year]['births'] += $c['births'] ?? 0;
            $yearly[$year]['deaths'] += $c['deaths'] ?? 0;
            $rangeBirths += $c['births'] ?? 0;
            $rangeDeaths += $c['deaths'] ?? 0;
        }
        unset($changes);
        $ages = [];
        for ($age = 0; $age <= 90; $age += 10) {
            $ages[] = ['band'=>$age === 90 ? '90+' : $age.'–'.($age+9),'male'=>0,'female'=>0];
        }
        $deathAges = array_map(static fn(string $label): array => ['band'=>$label,'count'=>0],
            ['Under 1 year','1–4','5–17','18–34','35–49','50–64','65–79','80+']);
        $infants = array_map(static fn(string $label): array => ['band'=>$label,'count'=>0],
            ['0–29 days','30–89 days','90–179 days','180–364 days']);
        $generations = [];
        $deathRecords = $stream ? $spool() : [];
        $personRows = $stream ? $spool() : [];
        foreach ($rows() as $p) {
            if ((int)$p['birth_day'] > $to) { continue; }
            $dead = $p['death_day'] !== null && (int)$p['death_day'] <= $to;
            $age = max(0,($dead ? (int)$p['death_day'] : $to)-(int)$p['birth_day']+(int)$p['initial_age_days']);
            $years = intdiv($age,365);
            $gen = $ancestry->generation((int)$p['id']);
            $generations[$gen] ??= ['generation'=>$gen,'living'=>0,'deceased'=>0];
            $generations[$gen][$dead ? 'deceased' : 'living']++;
            if (!$dashboard) $append($personRows,['world_id'=>$worldId,'person_id'=>(int)$p['id'],'name'=>$p['name'],'sex'=>$p['sex'],
                'birth_day'=>(int)$p['birth_day'],'initial_age_days'=>(int)$p['initial_age_days'],
                'death_day'=>$dead ? (int)$p['death_day'] : null,'death_cause'=>$dead ? ($p['cause'] ?? 'unknown') : '',
                'age_days'=>$age,'age_years'=>$years,'mother_id'=>$p['mother_id'],'father_id'=>$p['father_id'],'generation'=>$gen]);
            if (!$dead) { $ages[min(9,intdiv($years,10))][$p['sex']]++; }
            elseif ((int)$p['death_day'] >= $from) {
                $bucket = match (true) { $years < 1=>0,$years < 5=>1,$years < 18=>2,$years < 35=>3,$years < 50=>4,$years < 65=>5,$years < 80=>6,default=>7 };
                $deathAges[$bucket]['count']++;
                if ($age < 365) { $infants[$age < 30 ? 0 : ($age < 90 ? 1 : ($age < 180 ? 2 : 3))]['count']++; }
                if (!$dashboard || count($deathRecords) < 200) $append($deathRecords,['person_id'=>(int)$p['id'],'name'=>$p['name'],'day'=>(int)$p['death_day'],'age_days'=>$age,'age_years'=>$years,'cause'=>$p['cause'] ?? 'unknown']);
            }
        }
        ksort($generations);
        fclose($people);
        unset($ancestry);
        return ['world'=>$world,'from'=>$from,'to'=>$to,'summary'=>['living'=>$living,'births'=>$births,'deaths'=>$deaths,
            'total_people'=>$total,'generations'=>count($generations),'range_births'=>$rangeBirths,'range_deaths'=>$rangeDeaths],
            'daily'=>$result($daily),'yearly'=>array_values($yearly),'ages'=>$ages,'death_ages'=>$deathAges,'infants'=>$infants,
            'generations'=>array_values($generations),'deaths'=>$result($deathRecords),'people'=>$result($personRows),'events'=>$events,
            'display'=>['daily_step'=>$dailyStep,'death_rows'=>$stream ? $rangeDeaths : count($deathRecords),'death_rows_total'=>$rangeDeaths]];
    }
}
