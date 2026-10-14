<?php
declare(strict_types=1);
require_once __DIR__.'/support/database-guard.php';
require_once dirname(__DIR__).'/api/integrations/france-travail/employers.php';
$db=testDatabaseGuard('INTEGRATION_TEST_DB'); $db->beginTransaction();
try {
    $local=$db->query("SELECT c.id,c.name FROM companies c JOIN advertisements a ON a.company_id=c.id WHERE a.source='LOCAL' LIMIT 1")->fetch();
    if (!$local) throw new RuntimeException('Demo local company required');
    $external='synthetic-'.bin2hex(random_bytes(8));
    $employer=importedEmployer($db,$external,$local['name']);
    if ($employer===(int)$local['id']) throw new RuntimeException('Homonymous local employer reused');
    $q=$db->prepare("INSERT INTO advertisements (company_id,title,short_description,description,location,source,external_id) VALUES (?,'Import test','Summary','Description','Nantes','FRANCE_TRAVAIL',?)");$q->execute([$employer,$external]);
    if (importedEmployer($db,$external,'Changed label')!==$employer) throw new RuntimeException('Source offer identity lost');
    $q=$db->prepare("INSERT INTO advertisements (company_id,title,short_description,description,location) VALUES (?,'Local test','Summary','Description','Nantes')");$q->execute([$employer]);
    $blocked=false; try { importedEmployer($db,$external,$local['name']); } catch (RuntimeException) { $blocked=true; }
    if (!$blocked) throw new RuntimeException('Unsafe existing employer association accepted');
    echo "PASS imported employer isolation, stable source identity, unsafe association rejection\n";
} finally { $db->rollBack(); }
