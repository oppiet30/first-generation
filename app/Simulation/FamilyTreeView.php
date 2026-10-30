<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use PDO;
use RuntimeException;

/** Small, indexed neighborhoods; never loads the entire world's genealogy. */
final class FamilyTreeView
{
    public function __construct(private PDO $db) {}

    public function read(int $worldId, ?int $personId = null, string $search = '', int $after = 0): array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id,name,current_day FROM worlds WHERE id=?');
            $q->execute([$worldId]);
            $world = $q->fetch(PDO::FETCH_ASSOC);
            if (!$world) { throw new RuntimeException('World not found'); }
            $columns = 'id,name,sex,birth_day,initial_age_days,death_day,mother_id,father_id';
            $normalize = static function (array $p) use ($world): array {
                foreach (['id','birth_day','initial_age_days','death_day','mother_id','father_id'] as $key) {
                    $p[$key] = $p[$key] === null ? null : (int)$p[$key];
                }
                $p['age_years'] = intdiv(max(0,($p['death_day'] ?? (int)$world['current_day'])-$p['birth_day']+$p['initial_age_days']),365);
                return $p;
            };
            if ($search !== '') {
                // Treat wildcard characters literally; prefix search uses migration 007.
                $prefix = str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
                $q = $this->db->prepare("SELECT $columns FROM people WHERE world_id=? AND name LIKE ? ESCAPE '!' ORDER BY name,id LIMIT 21");
                $q->execute([$worldId,$prefix]);
                $matches = $q->fetchAll(PDO::FETCH_ASSOC);
                $more = count($matches)>20;
                $result = ['world'=>$world,'matches'=>array_map($normalize,array_slice($matches,0,20)),'more'=>$more];
            } else {
                $q = $this->db->prepare("SELECT $columns FROM people WHERE world_id=?".($personId === null ? ' ORDER BY id LIMIT 1' : ' AND id=?'));
                $q->execute($personId === null ? [$worldId] : [$worldId,$personId]);
                $person = $q->fetch(PDO::FETCH_ASSOC);
                if (!$person) { throw new RuntimeException('Person not found in world'); }
                $person = $normalize($person);
                $q = $this->db->prepare("SELECT $columns FROM people WHERE world_id=? AND id IN (?,?) ORDER BY id");
                $q->execute([$worldId,$person['mother_id'],$person['father_id']]);
                $parents = array_map($normalize,$q->fetchAll(PDO::FETCH_ASSOC));
                // Separate parent indexes avoid a world-wide scan for an OR condition.
                $parent = $person['sex'] === 'female' ? 'mother_id' : 'father_id';
                $q = $this->db->prepare("SELECT $columns FROM people WHERE $parent=? AND id>? AND world_id=? ORDER BY id LIMIT 21");
                $q->execute([$person['id'],$after,$worldId]);
                $children = $q->fetchAll(PDO::FETCH_ASSOC);
                $more = count($children)>20;
                $children = array_map($normalize,array_slice($children,0,20));
                $result = ['world'=>$world,'person'=>$person,'parents'=>$parents,'children'=>$children,
                    'next_after'=>$more ? end($children)['id'] : null];
            }
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }
}
