<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

// REMOTE_ADDR is authoritative. Never trust a caller-supplied forwarded IP header.
function rateLimit(PDO $db, string $scope, string $identity, int $limit, int $seconds): void {
    $bucket=hash('sha256',$scope.'|'.$identity);
    $q=$db->prepare('INSERT INTO auth_rate_limits (bucket,attempts,expires_at) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<=UTC_TIMESTAMP(),1,attempts+1),expires_at=IF(expires_at<=UTC_TIMESTAMP(),VALUES(expires_at),expires_at)');
    $q->execute([$bucket,$seconds]);
    $q=$db->prepare('SELECT attempts,GREATEST(1,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),expires_at)) AS retry_after FROM auth_rate_limits WHERE bucket=?'); $q->execute([$bucket]); $row=$q->fetch();
    if ((int)$row['attempts']>$limit) { header('Retry-After: '.(int)$row['retry_after']); reply(429,['error'=>'Too many attempts. Please try again later.']); }
}
function accountRateLimits(PDO $db, string $scope, string $email, int $perEmail=5, int $perIp=20, int $seconds=900): void {
    rateLimit($db,$scope.':ip',$_SERVER['REMOTE_ADDR']??'unknown',$perIp,$seconds);
    rateLimit($db,$scope.':email',strtolower($email),$perEmail,$seconds);
}
