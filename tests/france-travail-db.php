<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/config/database.php';
require dirname(__DIR__).'/api/integrations/france-travail/import.php';
if (getenv('INTEGRATION_TEST_DB')!=='1' || !str_ends_with((string)database()->query('SELECT DATABASE()')->fetchColumn(),'_test')) throw new RuntimeException('Disposable *_test DB required');
$db=database(); $name='Synthetic import '.bin2hex(random_bytes(8)); $prefix='test-'.bin2hex(random_bytes(8));
$offer=static fn(int $id)=>['id'=>$prefix.'-'.$id,'intitule'=>'Synthetic offer','description'=>'Synthetic description','entreprise'=>['nom'=>$name]];
$requests=[];
$fetch=static function(int $start,int $end) use (&$requests,$offer): array { $requests[]=[$start,$end];return ['resultats'=>array_map($offer,range($start,min(50,$end)))]; };
try {
    $result=syncFranceTravail($db,$fetch,100); if ($result!==['count'=>51,'complete'=>true] || $requests!==[[0,49],[50,99]]) throw new RuntimeException('Pagination failed'); echo "PASS provider pagination across two pages\n";
    syncFranceTravail($db,$fetch,100); $q=$db->prepare('SELECT COUNT(*) FROM advertisements WHERE external_id LIKE ?'); $q->execute([$prefix.'-%']); if ((int)$q->fetchColumn()!==51) throw new RuntimeException('Import duplicates'); echo "PASS repeat sync updates without duplicate offers\n";
    if (syncFranceTravail($db,$fetch,50)!==['count'=>50,'complete'=>false]) throw new RuntimeException('Missing truncation flag'); echo "PASS configured cap reports incomplete sync\n";
    try { syncFranceTravail($db,static fn()=>['invalid'=>true],50); throw new LogicException('Invalid response accepted'); } catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; }
    if ($db->inTransaction()) throw new RuntimeException('Leaked transaction'); echo "PASS malformed provider response fails safely\n";
    if ((int)$db->query("SELECT IS_FREE_LOCK('alternatech_france_travail_sync')")->fetchColumn()!==1) throw new RuntimeException('Leaked importer lock'); echo "PASS importer releases lock on failure\n";
} finally { $db->prepare('DELETE FROM companies WHERE name=?')->execute([$name]); }
