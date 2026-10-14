<?php
declare(strict_types=1);
// Read-only by default. Never prints password values or removes columns.
require_once dirname(__DIR__).'/api/config/database.php';
require_once __DIR__.'/migration-lib.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $apply=false; $backup=false; $expected=null;
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--apply') $apply=true;
        elseif ($arg==='--confirm-backup') $backup=true;
        elseif (preg_match('/^--database=([a-zA-Z0-9_]+)$/D',$arg,$m)) $expected=$m[1];
        else throw new RuntimeException('Unknown option');
    }
    $db=database(); $snapshot=schemaSnapshot($db); $name=$snapshot['database'];
    if (verifyCanonical($snapshot)) throw new RuntimeException('Canonical migration must be completed first');
    if (!col($snapshot,'people','password')) { echo "No legacy password column; nothing to clear.\n"; exit; }
    $count=(int)$db->query('SELECT COUNT(*) FROM people WHERE password IS NOT NULL')->fetchColumn();
    echo "Legacy password rows: $count (values withheld).\n";
    if (!$apply) { echo "Dry run only. Apply with --apply --database=<name> --confirm-backup after validating account access.\n"; exit; }
    if ($expected!==$name || (!$backup && !str_ends_with($name,'_test'))) throw new RuntimeException('Exact database confirmation and backup confirmation required');
    $db->beginTransaction();
    $rows=$db->query('SELECT p.id,u.password_hash FROM people p LEFT JOIN users u ON u.id=p.user_id WHERE p.password IS NOT NULL FOR UPDATE')->fetchAll();
    foreach ($rows as $row) if (!is_string($row['password_hash']) || !password_get_info($row['password_hash'])['algo']) throw new RuntimeException('A legacy profile has no valid migrated account hash; reset/migrate it first');
    $db->exec('UPDATE people SET password=NULL WHERE password IS NOT NULL'); $db->commit();
    echo "Legacy password values cleared. Existing backups still require restricted access and retention management.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'Cleanup refused: '.($e instanceof PDOException ? 'database error' : $e->getMessage())."\n"); exit(1);
}
