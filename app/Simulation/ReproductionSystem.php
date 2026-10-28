<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use PDO;

/** Called inside the world tick transaction, with the world row locked. */
final class ReproductionSystem
{
    public function __construct(private PDO $db) {}

    public function advance(int $worldId, int $day, DeterministicRandom $rng): void
    {
        $q = $this->db->prepare('SELECT id,mother_id,father_id FROM pregnancies WHERE world_id=? AND delivered_day IS NULL AND due_day<=? ORDER BY id');
        $q->execute([$worldId,$day]);
        $birth = $this->db->prepare('INSERT INTO people(world_id,name,sex,birth_day,mother_id,father_id) VALUES (?,?,?,?,?,?)');
        $finish = $this->db->prepare('UPDATE pregnancies SET delivered_day=?,child_id=? WHERE id=? AND delivered_day IS NULL');
        $event = $this->db->prepare('INSERT INTO world_events(world_id,day,person_id,event_type,details) VALUES (?,?,?,?,?)');
        $motherAlive = $this->db->prepare('SELECT COUNT(*) FROM people WHERE world_id=? AND id=? AND death_day IS NULL');
        $mortality = new MortalitySystem($this->db);
        foreach ($q->fetchAll() as $pregnancy) {
            $motherAlive->execute([$worldId,$pregnancy['mother_id']]);
            if ((int)$motherAlive->fetchColumn() === 0) {
                $finish->execute([$day,null,$pregnancy['id']]);
                continue;
            }
            // Illustrative 0.2% maternal death risk at delivery.
            if ($rng->nextInt(1000) < 2) {
                $mortality->kill($worldId,(int)$pregnancy['mother_id'],$day,'childbirth');
            }
            $sex = $rng->nextInt(2) === 0 ? 'female' : 'male';
            $name = 'Child-' . $worldId . '-' . $pregnancy['id'];
            $birth->execute([$worldId,$name,$sex,$day,$pregnancy['mother_id'],$pregnancy['father_id']]);
            $childId = (int)$this->db->lastInsertId();
            $finish->execute([$day,$childId,$pregnancy['id']]);
            $event->execute([$worldId,$day,$childId,'birth',$name . ' was born']);
        }

        // Only fetch adults eligible to reproduce; avoid hydrating every living person.
        $peopleQuery = $this->db->prepare(
            "SELECT id,sex,mother_id,father_id FROM people
             WHERE world_id=? AND death_day IS NULL
               AND (? - birth_day + initial_age_days) >= 18*365
               AND (? - birth_day + initial_age_days) <=
                   CASE WHEN sex='female' THEN 45*365 ELSE 65*365 END
             ORDER BY id"
        );
        $peopleQuery->execute([$worldId,$day,$day]);
        $women = [];
        $men = [];
        while ($person = $peopleQuery->fetch(PDO::FETCH_ASSOC)) {
            $person['id'] = (int)$person['id'];
            if ($person['sex'] === 'female') {
                $women[] = $person;
            } elseif ($person['sex'] === 'male') {
                $men[] = $person;
            }
        }
        $settingsQuery = $this->db->prepare('SELECT allow_parent_child_partnerships FROM world_relationship_settings WHERE world_id=?');
        $settingsQuery->execute([$worldId]);
        $allowParentChild = (bool)($settingsQuery->fetchColumn() ?: false);

        $pairQuery = $this->db->prepare('SELECT mother_id,father_id FROM partnerships WHERE world_id=? AND ended_day IS NULL ORDER BY id');
        $pairQuery->execute([$worldId]);
        $pairs = $pairQuery->fetchAll(PDO::FETCH_ASSOC);
        $paired = [];
        foreach ($pairs as $pair) {
            $paired[(int)$pair['mother_id']] = true;
            $paired[(int)$pair['father_id']] = true;
        }

        // Match in ascending ID order, as before, but skip already-paired men
        // using a moving cursor rather than rescanning the entire population.
        if ($day === 1 || $day % 30 === 0) {
            $add = $this->db->prepare('INSERT INTO partnerships(world_id,mother_id,father_id,started_day) VALUES (?,?,?,?)');
            $cursor = 0;
            $menCount = count($men);
            foreach ($women as $mother) {
                $mid = $mother['id'];
                if (isset($paired[$mid])) { continue; }
                while ($cursor < $menCount && isset($paired[$men[$cursor]['id']])) { ++$cursor; }
                for ($j = $cursor; $j < $menCount; ++$j) {
                    $father = $men[$j];
                    $fid = $father['id'];
                    if (isset($paired[$fid])) { continue; }
                    if (!$allowParentChild &&
                        ($mother['mother_id'] == $fid || $mother['father_id'] == $fid ||
                         $father['mother_id'] == $mid || $father['father_id'] == $mid)) { continue; }
                    $add->execute([$worldId,$mid,$fid,$day]);
                    $paired[$mid] = $paired[$fid] = true;
                    $pairs[] = ['mother_id'=>$mid,'father_id'=>$fid];
                    $event->execute([$worldId,$day,$mid,'partnership','Partnership with person #'.$fid]);
                    break;
                }
            }
        }

        // Check eligibility for existing couples in one query, not by keeping
        // the entire living population in PHP memory.
        $eligiblePair = $this->db->prepare(
            "SELECT COUNT(*) FROM people WHERE world_id=? AND id IN (?,?)
             AND death_day IS NULL
             AND (? - birth_day + initial_age_days) >= 18*365
             AND (? - birth_day + initial_age_days) <=
                 CASE WHEN sex='female' THEN 45*365 ELSE 65*365 END"
        );
        $active = $this->db->prepare('SELECT COUNT(*) FROM pregnancies WHERE world_id=? AND mother_id=? AND delivered_day IS NULL');
        $latest = $this->db->prepare('SELECT MAX(conceived_day) FROM pregnancies WHERE world_id=? AND mother_id=?');
        $conceive = $this->db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_day,due_day) VALUES (?,?,?,?,?)');
        foreach ($pairs as $pair) {
            $mid = (int)$pair['mother_id']; $fid = (int)$pair['father_id'];
            $eligiblePair->execute([$worldId,$mid,$fid,$day,$day]);
            if ((int)$eligiblePair->fetchColumn() !== 2) { continue; }
            $active->execute([$worldId,$mid]);
            if ((int)$active->fetchColumn() > 0) { continue; }
            $latest->execute([$worldId,$mid]);
            $last = $latest->fetchColumn();
            if ($last !== null && $day - (int)$last < 365) { continue; }
            if ($rng->nextInt(100) !== 0) { continue; }
            $conceive->execute([$worldId,$mid,$fid,$day,$day+280]);
            $event->execute([$worldId,$day,$mid,'conception','Pregnancy with father #'.$fid]);
        }
    }
}
