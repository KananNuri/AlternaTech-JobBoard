<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/auth/rate-limit.php';
$directory=sys_get_temp_dir().'/alternatech-limiter-test-'.bin2hex(random_bytes(8));
function demand(bool $condition): void { if (!$condition) throw new RuntimeException('Rate limiter assertion failed'); }
try {
    for ($i=0;$i<10;$i++) demand(consumeLoginAttempt('127.0.0.1','one@example.invalid',$directory,1000));
    demand(!consumeLoginAttempt('127.0.0.1','one@example.invalid',$directory,1000));
    demand(!consumeLoginAttempt('127.0.0.2','ONE@example.invalid',$directory,1000));
    for ($i=0;$i<50;$i++) demand(consumeLoginAttempt('127.0.0.1',"other-$i@example.invalid",$directory,1000));
    demand(!consumeLoginAttempt('127.0.0.1','another@example.invalid',$directory,1000));
    demand(consumeLoginAttempt('127.0.0.1','one@example.invalid',$directory,1060));
    demand(!str_contains(file_get_contents($directory.'/attempts.json'),'example.invalid'));
    echo "PASS shared account/IP limits, cookie independence, expiry, no plaintext identifiers\n";
} finally {
    if (is_file($directory.'/attempts.json')) unlink($directory.'/attempts.json');
    if (is_dir($directory)) rmdir($directory);
}
