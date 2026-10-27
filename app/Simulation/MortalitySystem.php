<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;
use PDO;

/** Illustrative mortality rates, not historical estimates. Runs within the world transaction. */
final class MortalitySystem
{
    public function __construct(private PDO $db) {}

    public function advance(int $worldId, int $day, DeterministicRandom $rng): void
    {
        $q = $this->db->prepare('SELECT id,birth_day,initial_age_days FROM people WHERE world_id=? AND death_day IS NULL ORDER BY id');
        $q->execute([$worldId]);
        foreach ($q->fetchAll() as $p) {
            $age = intdiv(max(0, $day - (int)$p['birth_day'] + (int)$p['initial_age_days']),365);
            $risk = match (true) {
                $age === 0 => 80,
                $age < 5 => 20,
                $age < 18 => 5,
                $age < 50 => 8,
                $age < 65 => 45,
                $age < 80 => 250,
                $age < 95 => 1200,
                default => 5000
            };
            if ($rng->nextInt(1000000) < $risk) {
                $this->kill($worldId,(int)$p['id'],$day,'natural');
            }
        }
    }

    public function kill(int $worldId, int $personId, int $day, string $cause): void
    {
        $q = $this->db->prepare('UPDATE people SET death_day=? WHERE world_id=? AND id=? AND death_day IS NULL');
        $q->execute([$day,$worldId,$personId]);
        if ($q->rowCount() === 0) { return; }
        $q = $this->db->prepare('INSERT INTO deaths(world_id,person_id,day,cause) VALUES (?,?,?,?)');
        $q->execute([$worldId,$personId,$day,$cause]);
        $q = $this->db->prepare('INSERT INTO world_events(world_id,day,person_id,event_type,details) VALUES (?,?,?,?,?)');
        $q->execute([$worldId,$day,$personId,'death','Death: '.$cause]);
        $q = $this->db->prepare('UPDATE partnerships SET ended_day=? WHERE world_id=? AND ended_day IS NULL AND (mother_id=? OR father_id=?)');
        $q->execute([$day,$worldId,$personId,$personId]);
    }

    public function completeIfExtinct(int $worldId, int $day): bool
    {
        $q = $this->db->prepare('SELECT COUNT(*) FROM people WHERE world_id=? AND death_day IS NULL');
        $q->execute([$worldId]);
        if ((int)$q->fetchColumn() > 0) { return false; }
        $q = $this->db->prepare("UPDATE worlds SET status='completed' WHERE id=?");
        $q->execute([$worldId]);
        $q = $this->db->prepare('INSERT INTO world_events(world_id,day,event_type,details) VALUES (?,?,?,?)');
        $q->execute([$worldId,$day,'extinction','No living people remain']);
        return true;
    }
}
