<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Simulation/StatisticsAncestry.php';
use FirstGeneration\Simulation\StatisticsAncestry;
function expectDepth(StatisticsAncestry $index,int $id,int $depth): void {
    if ($index->generation($id) !== $depth) { throw new RuntimeException('Incorrect generation'); }
}
$index = new StatisticsAncestry();
$index->add(1000000000,0,0);
$index->add(1000000002,0,0);
$index->add(1000000003,1000000000,1000000002);
$index->add(1000000004,1000000003,1000000002);
expectDepth($index,1000000004,3); // Recursive lookups before parents are cached.
expectDepth($index,1000000000,1);
expectDepth($index,1000000003,2);
foreach ([999999999,1000000001,1000000005] as $missing) {
    try { $index->generation($missing); throw new LogicException('Missing parent accepted'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Parent missing from world') { throw $error; } }
}
$cycle = new StatisticsAncestry();
$cycle->add(1,2,0); $cycle->add(2,1,0);
try { $cycle->generation(1); throw new LogicException('Cycle accepted'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'Genealogy contains a cycle') { throw $error; } }
echo "PASS: sparse and large IDs, recursive ancestry, cache, missing parents and cycle detection\n";
