<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use PDO;
use RuntimeException;
use Throwable;

/** A tick advances one simulation day; mutations and clock advance atomically. */
final class WorldEngine
{
    public function __construct(private PDO $db) {}

    public function tick(int $worldId): array
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id, current_day, status, rng_state FROM worlds WHERE id=? FOR UPDATE');
            $q->execute([$worldId]);
            $world = $q->fetch();
            if (!$world || $world['status'] !== 'running') {
                throw new RuntimeException('World not found or not running');
            }
            $day = (int)$world['current_day'] + 1;
            // Preserve RNG state for future deterministic simulation systems.
            $rng = new DeterministicRandom((int)$world['rng_state']);
            (new ReproductionSystem($this->db))->advance($worldId, $day, $rng);
            $mortality = new MortalitySystem($this->db);
            $mortality->advance($worldId,$day,$rng);
            $extinct = $mortality->completeIfExtinct($worldId,$day);
            $q = $this->db->prepare('UPDATE worlds SET current_day=?, rng_state=? WHERE id=?');
            $q->execute([$day, $rng->state(), $worldId]);
            $this->db->commit();
            return ['day' => $day, 'year' => intdiv($day - 1, 365) + 1,
                    'day_of_year' => (($day - 1) % 365) + 1, 'extinct' => $extinct];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
