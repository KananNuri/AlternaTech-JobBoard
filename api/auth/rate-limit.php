<?php
declare(strict_types=1);

/** Shared, locked storage survives session/cookie changes. No credentials are stored. */
function consumeLoginAttempt(string $ip, string $email, ?string $directory = null, ?int $now = null): bool {
    $now ??= time();
    $directory ??= sys_get_temp_dir().'/alternatech-login-'.hash('sha256', dirname(__DIR__, 2));
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) throw new RuntimeException('Login limiter unavailable');
    $path = $directory.'/attempts.json';
    if (is_link($path)) throw new RuntimeException('Login limiter unavailable');
    $file = fopen($path, 'c+');
    if (!$file) throw new RuntimeException('Login limiter unavailable');
    try {
        if (!flock($file, LOCK_EX)) throw new RuntimeException('Login limiter unavailable');
        $raw = stream_get_contents($file, 2097153);
        if ($raw === false || strlen($raw)>2097152) throw new RuntimeException('Login limiter unavailable');
        $buckets = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($buckets)) throw new RuntimeException('Login limiter unavailable');
        foreach ($buckets as $key=>$bucket) if ($bucket['start'] <= $now-60) unset($buckets[$key]);
        $keys = [hash('sha256','ip:'.$ip)=>60, hash('sha256','account:'.strtolower($email))=>10];
        $allowed = count($buckets)<10000;
        foreach ($keys as $key=>$limit) if (($buckets[$key]['count']??0)>=$limit) $allowed=false;
        if ($allowed) foreach ($keys as $key=>$limit) {
            $buckets[$key] ??= ['start'=>$now,'count'=>0]; $buckets[$key]['count']++;
        }
        $encoded=json_encode($buckets, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file,0) || fwrite($file,$encoded)!==strlen($encoded) || !fflush($file)) throw new RuntimeException('Login limiter unavailable');
        return $allowed;
    } finally { flock($file, LOCK_UN); fclose($file); }
}
