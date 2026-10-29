<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

use RuntimeException;

/** Disk-backed parents and a four-byte-per-ID generation cache. */
final class StatisticsAncestry
{
    private $parents;
    private ?int $base = null;
    private int $writeOffset = 0;
    private ?int $readOffset = null;
    private string $depths = '';
    private array $visiting = [];

    public function __construct()
    {
        $this->parents = tmpfile();
        if ($this->parents === false) { throw new RuntimeException('Cannot create ancestry spool'); }
    }

    public function add(int $id, int $mother, int $father): void
    {
        $this->base ??= $id;
        $index = $id - $this->base;
        if ($index < 0 || $index < intdiv(strlen($this->depths),4)) {
            throw new RuntimeException('Ancestry IDs must be read in ascending order');
        }
        // Offset parent IDs by one so an unwritten sparse-file record is
        // distinguishable from a founder with two null parents.
        $offset = $index * 16;
        if ($offset !== $this->writeOffset && fseek($this->parents,$offset) !== 0) {
            throw new RuntimeException('Cannot seek ancestry spool');
        }
        if (fwrite($this->parents,pack('JJ',$mother+1,$father+1)) !== 16) {
            throw new RuntimeException('Cannot write ancestry spool');
        }
        $this->writeOffset = $offset+16;
        $this->readOffset = null;
        $this->depths .= str_repeat("\0",($index+1)*4-strlen($this->depths));
    }

    public function generation(int $id): int
    {
        $index = $this->base === null ? -1 : $id-$this->base;
        if ($index < 0 || ($index+1)*4 > strlen($this->depths)) { throw new RuntimeException('Parent missing from world'); }
        $offset = $index*4;
        $cached = unpack('Ndepth',$this->depths,$offset)['depth'];
        if ($cached !== 0) { return $cached; }
        if (isset($this->visiting[$id])) { throw new RuntimeException('Genealogy contains a cycle'); }
        $fileOffset = $index*16;
        if ($fileOffset !== $this->readOffset && fseek($this->parents,$fileOffset) !== 0) {
            throw new RuntimeException('Cannot seek ancestry spool');
        }
        $record = fread($this->parents,16);
        $this->readOffset = $fileOffset+16;
        if ($record === false || strlen($record) !== 16) { throw new RuntimeException('Cannot read ancestry spool'); }
        $parents = unpack('Jmother/Jfather',$record);
        if ($parents['mother'] === 0 || $parents['father'] === 0) { throw new RuntimeException('Parent missing from world'); }
        $this->visiting[$id] = true;
        try {
            $depth = 1;
            foreach ($parents as $encodedParent) {
                if ($encodedParent > 1) { $depth = max($depth,1+$this->generation($encodedParent-1)); }
            }
            $bytes = pack('N',$depth);
            for ($i=0;$i<4;$i++) { $this->depths[$offset+$i] = $bytes[$i]; }
            return $depth;
        } finally { unset($this->visiting[$id]); }
    }

    public function __destruct()
    {
        if (is_resource($this->parents)) { fclose($this->parents); }
    }
}
