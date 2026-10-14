<?php
declare(strict_types=1);
require_once __DIR__.'/support/database-guard.php';
$db=testDatabaseGuard('INTEGRATION_TEST_DB');
function maintenanceTool(array $args): int {
    $process=proc_open([PHP_BINARY,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
}
function maintenanceCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Maintenance tool check failed'); }
$column=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people' AND COLUMN_NAME='password'")->fetchColumn();
if ($column) throw new RuntimeException('Use a fresh canonical test schema without historical credentials');
$id=null;
try {
    $db->exec('ALTER TABLE people ADD password VARCHAR(255) NULL');
    $db->exec("UPDATE people SET password='synthetic-not-a-secret' WHERE user_id IS NOT NULL");
    $before=(int)$db->query('SELECT COUNT(*) FROM people WHERE password IS NOT NULL')->fetchColumn();
    maintenanceCheck($before>0);
    maintenanceCheck(maintenanceTool(['tools/clear-legacy-passwords.php'])===0);
    maintenanceCheck((int)$db->query('SELECT COUNT(*) FROM people WHERE password IS NOT NULL')->fetchColumn()===$before);
    maintenanceCheck(maintenanceTool(['tools/clear-legacy-passwords.php','--apply','--database=alternatech'])!==0);
    $q=$db->prepare("INSERT INTO users (firstname,lastname,email,password_hash) VALUES ('Locked','Fixture',?,'!locked:synthetic')");$q->execute(['maintenance-'.bin2hex(random_bytes(6)).'@example.invalid']);$id=(int)$db->lastInsertId();
    $q=$db->prepare("INSERT INTO people (user_id,firstname,lastname,email,password) SELECT id,firstname,lastname,email,'synthetic' FROM users WHERE id=?");$q->execute([$id]);
    maintenanceCheck(maintenanceTool(['tools/clear-legacy-passwords.php','--apply','--database='.testDatabaseName()])!==0);
    $q=$db->prepare('UPDATE users SET password_hash=? WHERE id=?');$q->execute([password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$id]);
    maintenanceCheck(maintenanceTool(['tools/clear-legacy-passwords.php','--apply','--database='.testDatabaseName()])===0);
    maintenanceCheck((int)$db->query('SELECT COUNT(*) FROM people WHERE password IS NOT NULL')->fetchColumn()===0);
    echo "PASS legacy cleanup dry run, exact target guard, locked-account refusal, validated-account cleanup\n";
} finally {
    if ($id) { $db->prepare('DELETE FROM people WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
    $db->exec('ALTER TABLE people DROP COLUMN password');
}
