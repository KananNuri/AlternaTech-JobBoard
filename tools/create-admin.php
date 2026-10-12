<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/config/database.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$email=$argv[1]??'';
if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"Usage: php tools/create-admin.php existing-account-email\nRegister the account in the app first.\n"); exit(2); }
try {
    $db=database(); $q=$db->prepare("UPDATE users SET role='admin' WHERE email=?"); $q->execute([$email]);
    $q=$db->prepare("SELECT id FROM users WHERE email=? AND role='admin'"); $q->execute([$email]);
    if (!$q->fetch()) throw new RuntimeException('Register the account first');
    echo "Administrator access granted to the existing account.\n";
} catch (Throwable $e) { fwrite(STDERR,"Unable to grant administrator access. Check account and database configuration.\n"); exit(1); }
