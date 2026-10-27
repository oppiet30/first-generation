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
        foreach ($q->fetchAll() as $pregnancy) {
            $sex = $rng->nextInt(2) === 0 ? 'female' : 'male';
            $name = 'Child-' . $worldId . '-' . $pregnancy['id'];
            $birth->execute([$worldId,$name,$sex,$day,$pregnancy['mother_id'],$pregnancy['father_id']]);
            $childId = (int)$this->db->lastInsertId();
            $finish->execute([$day,$childId,$pregnancy['id']]);
            $event->execute([$worldId,$day,$childId,'birth',$name . ' was born']);
        }

        // Build stable, exclusive adult partnerships. Sibling partnerships are
        // permitted in this fictional two-founder scenario; parent-child pairs are not.
        $peopleQuery = $this->db->prepare('SELECT id,name,sex,birth_day,initial_age_days,mother_id,father_id FROM people WHERE world_id=? AND death_day IS NULL ORDER BY id');
        $peopleQuery->execute([$worldId]);
        $people = $peopleQuery->fetchAll();
        $byId = [];
        foreach ($people as $person) { $byId[(int)$person['id']] = $person; }

        $pairQuery = $this->db->prepare('SELECT mother_id,father_id FROM partnerships WHERE world_id=? AND ended_day IS NULL ORDER BY id');
        $pairQuery->execute([$worldId]);
        $pairs = $pairQuery->fetchAll();
        $paired = [];
        foreach ($pairs as $pair) {
            $paired[(int)$pair['mother_id']] = true;
            $paired[(int)$pair['father_id']] = true;
        }
        $age = static fn(array $p): int => $day - (int)$p['birth_day'] + (int)$p['initial_age_days'];
        $eligible = static fn(array $p): bool => $age($p) >= 18*365
            && ($p['sex'] === 'female' ? $age($p) <= 45*365 : $age($p) <= 65*365);
        // Adam and Eve form the original partnership on the first tick.
        // Later partnerships form at 30-day intervals to keep simulation cost bounded.
        if ($day === 1 || $day % 30 === 0) {
            $add = $this->db->prepare('INSERT INTO partnerships(world_id,mother_id,father_id,started_day) VALUES (?,?,?,?)');
            foreach ($people as $mother) {
                $mid = (int)$mother['id'];
                if ($mother['sex'] !== 'female' || isset($paired[$mid]) || !$eligible($mother)) { continue; }
                foreach ($people as $father) {
                    $fid = (int)$father['id'];
                    if ($father['sex'] !== 'male' || isset($paired[$fid]) || !$eligible($father)) { continue; }
                    if ($mother['mother_id'] == $fid || $mother['father_id'] == $fid ||
                        $father['mother_id'] == $mid || $father['father_id'] == $mid) { continue; }
                    $add->execute([$worldId,$mid,$fid,$day]);
                    $paired[$mid] = $paired[$fid] = true;
                    $pairs[] = ['mother_id'=>$mid,'father_id'=>$fid];
                    $event->execute([$worldId,$day,$mid,'partnership','Partnership with person #'.$fid]);
                    break;
                }
            }
        }

        $active = $this->db->prepare('SELECT COUNT(*) FROM pregnancies WHERE world_id=? AND mother_id=? AND delivered_day IS NULL');
        $latest = $this->db->prepare('SELECT MAX(conceived_day) FROM pregnancies WHERE world_id=? AND mother_id=?');
        $conceive = $this->db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_day,due_day) VALUES (?,?,?,?,?)');
        foreach ($pairs as $pair) {
            $mid = (int)$pair['mother_id']; $fid = (int)$pair['father_id'];
            if (!isset($byId[$mid],$byId[$fid]) ||
                !$eligible($byId[$mid]) || !$eligible($byId[$fid])) { continue; }
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
