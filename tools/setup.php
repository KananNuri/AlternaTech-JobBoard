<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/config/database.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $host=envValue('DB_HOST','127.0.0.1');$port=envValue('DB_PORT','3306');$name=envValue('DB_NAME','alternatech');
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/D',$host) || !ctype_digit($port) || !preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Invalid database configuration');
    // Setup credentials need CREATE/ALTER; use a separate restricted runtime DB user afterwards.
    $db=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",envValue('DB_USER','alternatech'),envValue('DB_PASSWORD'),databaseOptions());
    $sql=file_get_contents(dirname(__DIR__).'/database/schema.sql');
    $sql=str_replace(['CREATE DATABASE IF NOT EXISTS alternatech','USE alternatech;'],["CREATE DATABASE IF NOT EXISTS `$name`","USE `$name`;"],$sql);
    $db->exec($sql);
    echo "Core schema installed.\n";
    require __DIR__.'/migrate.php';
    if (in_array('--demo',$argv,true)) {
        if ((int)$db->query('SELECT COUNT(*) FROM advertisements')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn()!==0) throw new RuntimeException('Demo seed requires empty advertisements, companies and categories; existing records were not overwritten');
        $db->exec(str_replace('USE alternatech;',"USE `$name`;",file_get_contents(dirname(__DIR__).'/database/seed.sql')));
        echo "20 fictional demo offers populated through the backend.\n";
    }
} catch (Throwable $e) { fwrite(STDERR,"Setup failed: ".$e->getMessage()."\n");exit(1); }
