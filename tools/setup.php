<?php
declare(strict_types=1);
// Installs the canonical schema into an EMPTY database (created if missing), optionally with demo offers.
// It never alters an existing database: one that already holds tables must follow the canonical contract,
// otherwise setup stops before running any statement (migrate it with tools/migrate.php first).
//   php tools/setup.php [--demo]
require dirname(__DIR__).'/api/config/database.php';
require __DIR__.'/migration-lib.php';
require __DIR__.'/company-access-schema.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $host=envValue('DB_HOST','127.0.0.1');$port=envValue('DB_PORT','3306');$name=envValue('DB_NAME','alternatech');
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/D',$host) || !ctype_digit($port) || !preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Invalid database configuration');
    // Setup credentials need CREATE/ALTER; use a separate restricted runtime DB user afterwards.
    $db=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",envValue('DB_USER','alternatech'),envValue('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $exists=$db->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $exists->execute([$name]);
    if ($exists->fetchColumn()) {
        $db->exec("USE `$name`");
        $decision=setupDecision(schemaSnapshot($db));
        if ($decision['action']==='refuse') {
            fwrite(STDERR,"Setup refused: `$name` already contains tables that do not follow the canonical schema. Nothing was changed.\n");
            foreach (array_slice($decision['problems'],0,8) as $p) fwrite(STDERR,"  - $p\n");
            fwrite(STDERR,"Review it with: php tools/migrate.php   (dry run, read-only)\n");
            exit(1);
        }
    } else $decision=['action'=>'install'];
    if ($decision['action']==='install') {
        $sql=file_get_contents(dirname(__DIR__).'/database/schema.sql');
        $sql=str_replace(['CREATE DATABASE IF NOT EXISTS alternatech','USE alternatech;'],["CREATE DATABASE IF NOT EXISTS `$name`","USE `$name`;"],$sql);
        $db->exec($sql);
        $db->exec("USE `$name`");
        foreach (companyAccessStatements($db) as $statement) $db->exec($statement);
        if ($problems=verifyCanonical(schemaSnapshot($db))) throw new RuntimeException('Installed schema is not canonical: '.implode('; ',$problems));
        echo "Canonical schema installed.\n";
    } else echo "Database already follows the canonical schema; nothing to install.\n";
    if (in_array('--demo',$argv,true)) {
        if ((int)$db->query('SELECT COUNT(*) FROM advertisements')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn()!==0) throw new RuntimeException('Demo seed requires empty advertisements, companies and categories; existing records were not overwritten');
        $db->exec(file_get_contents(dirname(__DIR__).'/database/seed.sql'));
        echo "20 fictional demo offers populated through the backend.\n";
    }
} catch (Throwable $e) { fwrite(STDERR,"Setup failed: ".($e instanceof PDOException?'SQLSTATE '.$e->getCode():$e->getMessage())."\n");exit(1); }
