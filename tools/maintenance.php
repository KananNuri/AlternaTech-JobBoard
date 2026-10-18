<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/config/database.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $db=database();
    foreach (['auth_rate_limits','account_tokens','mail_outbox'] as $table) $db->exec("DELETE FROM $table WHERE expires_at<=UTC_TIMESTAMP()");
    echo "Expired account tokens, rate limits and queued mail removed.\n";
} catch (Throwable $e) { fwrite(STDERR,"Maintenance failed. Check database configuration.\n");exit(1); }
