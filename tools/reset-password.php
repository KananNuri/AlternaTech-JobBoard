<?php
declare(strict_types=1);
// Sets a new password for one account, typically one locked by the migration (unrecognised legacy value).
//   php tools/reset-password.php --user-id=<users.id> [--force]
// The password is read from standard input (one line, 12 to 72 bytes) and is never printed or logged; pipe it
// from a password manager or type it in a private terminal. Without --force only locked accounts are changed.
// Hand the password to its owner through a separate, trusted channel.
require_once dirname(__DIR__).'/api/config/database.php';
require_once __DIR__.'/migration-lib.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$id=null; $force=false;
foreach (array_slice($argv,1) as $a) {
    if (preg_match('/^--user-id=([1-9][0-9]*)$/D',$a,$m)) $id=(int)$m[1];
    elseif ($a==='--force') $force=true;
    else { fwrite(STDERR,"Unknown option. Usage: php tools/reset-password.php --user-id=<id> [--force]\n"); exit(1); }
}
if ($id===null) { fwrite(STDERR,"Usage: php tools/reset-password.php --user-id=<id> [--force]\n"); exit(1); }
try {
    $db=database();
    $q=$db->prepare('SELECT password_hash FROM users WHERE id=?'); $q->execute([$id]); $hash=$q->fetchColumn();
    if ($hash===false) { fwrite(STDERR,"No account with id $id.\n"); exit(1); }
    if (!$force && !isLockedPasswordHash((string)$hash)) { fwrite(STDERR,"Account $id is not locked; use --force to replace a working password.\n"); exit(1); }
    $interactive=function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($interactive) { fwrite(STDERR,'New password (12 to 72 bytes): '); if (DIRECTORY_SEPARATOR==='/') shell_exec('stty -echo'); }
    $password=rtrim((string)fgets(STDIN),"\r\n");
    if ($interactive) { if (DIRECTORY_SEPARATOR==='/') shell_exec('stty echo'); fwrite(STDERR,"\n"); }
    if (strlen($password)<12 || strlen($password)>72) { fwrite(STDERR,"Refused: the password must contain 12 to 72 bytes.\n"); exit(1); }
    $u=$db->prepare('UPDATE users SET password_hash=? WHERE id=?'); $u->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
    unset($password);
    echo "Password updated for account $id.\n";
} catch (Throwable $e) { fwrite(STDERR,'Reset failed: '.($e instanceof PDOException?'SQLSTATE '.$e->getCode():$e->getMessage())."\n"); exit(1); }
