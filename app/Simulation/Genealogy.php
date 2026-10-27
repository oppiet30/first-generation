<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use PDO;
use RuntimeException;

final class Genealogy
{
    public function __construct(private PDO $db) {}

    public function people(int $worldId): array
    {
        $q = $this->db->prepare('SELECT p.id,p.name,p.sex,p.birth_day,p.initial_age_days,p.death_day,d.cause AS death_cause,p.mother_id,p.father_id,w.current_day FROM people p JOIN worlds w ON w.id=p.world_id LEFT JOIN deaths d ON d.person_id=p.id AND d.world_id=p.world_id WHERE p.world_id=? ORDER BY p.id');
        $q->execute([$worldId]);
        $people = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($people as &$person) {
            $end = $person['death_day'] === null ? (int)$person['current_day'] : (int)$person['death_day'];
            $days = max(0, $end - (int)$person['birth_day'] + (int)$person['initial_age_days']);
            $person['age_days'] = $days;
            $person['age_years'] = intdiv($days, 365);
            unset($person['current_day']);
        }
        unset($person);
        return $people;
    }

    public function ancestors(int $worldId, int $personId): array
    {
        $people = $this->people($worldId);
        $byId = [];
        foreach ($people as $person) { $byId[(int)$person['id']] = $person; }
        if (!isset($byId[$personId])) { throw new RuntimeException('Person not found in world'); }
        $result = [];
        $seen = [$personId => true];
        $queue = [[$personId, 0]];
        while ($queue) {
            [$id, $depth] = array_shift($queue);
            foreach (['mother_id','father_id'] as $column) {
                $parent = $byId[$id][$column];
                if ($parent === null) { continue; }
                $parent = (int)$parent;
                if (!isset($byId[$parent])) {
                    throw new RuntimeException('Parent missing from world');
                }
                if (isset($seen[$parent])) { continue; }
                $seen[$parent] = true;
                $result[] = ['generation' => $depth + 1, 'person' => $byId[$parent]];
                $queue[] = [$parent, $depth + 1];
            }
        }
        return $result;
    }
}
