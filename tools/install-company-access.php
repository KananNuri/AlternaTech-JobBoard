<?php
declare(strict_types=1);
require __DIR__.'/../api/config/database.php';
require __DIR__.'/migration-lib.php';
require __DIR__.'/company-access-schema.php';
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
try {
    $db=database();$name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($issues=verifyCanonical(schemaSnapshot($db))) throw new RuntimeException('Run the canonical migration first');
    $sql=companyAccessStatements($db);
    if (!in_array('--apply',$argv,true)) { echo "Dry run: add company proposals, join requests and company managers; no existing rows changed.\n";exit; }
    if (!in_array('--database='.$name,$argv,true)) throw new RuntimeException('Pass --database=<exact connected database>');
    if (!str_ends_with($name,'_test') && !in_array('--confirm-backup',$argv,true)) throw new RuntimeException('Back up the database and pass --confirm-backup');
    foreach ($sql as $statement) $db->exec($statement);
    echo "Company access extension installed (existing records preserved).\n";
} catch(Throwable $e) { fwrite(STDERR,($e instanceof PDOException?'SQLSTATE '.$e->getCode():$e->getMessage())."\n");exit(1); }
