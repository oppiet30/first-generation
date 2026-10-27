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

        // One founding couple; descendants' partnerships will be a separate feature.
        $q = $this->db->prepare("SELECT id,name,sex,birth_day,initial_age_days FROM people WHERE world_id=? AND death_day IS NULL AND name IN ('Adam','Eve') AND mother_id IS NULL AND father_id IS NULL ORDER BY id");
        $q->execute([$worldId]);
        $founders = $q->fetchAll();
        if (count($founders) !== 2) { return; }
        $mother = null; $father = null;
        foreach ($founders as $person) {
            if ($person['name'] === 'Eve' && $person['sex'] === 'female') { $mother = $person; }
            if ($person['name'] === 'Adam' && $person['sex'] === 'male') { $father = $person; }
        }
        if (!$mother || !$father) { return; }
        $age = static fn(array $p): int => $day - (int)$p['birth_day'] + (int)$p['initial_age_days'];
        if ($age($mother) < 18*365 || $age($mother) > 45*365 || $age($father) < 18*365) { return; }
        $q = $this->db->prepare('SELECT COUNT(*) FROM pregnancies WHERE world_id=? AND mother_id=? AND delivered_day IS NULL');
        $q->execute([$worldId,$mother['id']]);
        if ((int)$q->fetchColumn() !== 0) { return; }
        // Deterministic daily chance; minimum 365 days between conceptions.
        $q = $this->db->prepare('SELECT MAX(conceived_day) FROM pregnancies WHERE world_id=? AND mother_id=?');
        $q->execute([$worldId,$mother['id']]);
        $last = $q->fetchColumn();
        if ($last !== null && $day - (int)$last < 365) { return; }
        if ($rng->nextInt(100) !== 0) { return; }
        $q = $this->db->prepare('INSERT INTO pregnancies(world_id,mother_id,father_id,conceived_day,due_day) VALUES (?,?,?,?,?)');
        $q->execute([$worldId,$mother['id'],$father['id'],$day,$day+280]);
        $event->execute([$worldId,$day,$mother['id'],'conception','Eve became pregnant']);
    }
}
