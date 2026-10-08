<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/config/database.php';
class GoogleAccountConflict extends RuntimeException {}
function googleConfig(): array {
    $client = envValue('GOOGLE_CLIENT_ID'); $secret = envValue('GOOGLE_CLIENT_SECRET');
    $redirect = envValue('GOOGLE_REDIRECT_URI');
    $parts = parse_url($redirect);
    $valid = filter_var($redirect, FILTER_VALIDATE_URL) !== false && is_array($parts) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        && ($parts['path'] ?? '') === '/api/auth/google/callback'
        && (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost','127.0.0.1'], true)));
    return ['client'=>$client,'secret'=>$secret,'redirect'=>$redirect,'enabled'=>$client !== '' && $secret !== '' && $valid && extension_loaded('curl')];
}
function googleHttp(string $url, ?array $form = null): array {
    // Fixed Google endpoints only. Never accept a token or endpoint URL from the browser.
    if ($url !== 'https://oauth2.googleapis.com/token') throw new RuntimeException('Invalid provider endpoint');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($form ?? []),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json']]);
    $body=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body === false || $status !== 200 || strlen($body)>65536) throw new RuntimeException('Provider unavailable');
    $response=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($response)) throw new RuntimeException('Invalid provider response');
    return $response;
}
function googleState(array $pending, mixed $state, int $now): bool {
    return is_string($state) && is_string($pending['state'] ?? null) && hash_equals($pending['state'],$state)
        && is_int($pending['created'] ?? null) && $pending['created'] <= $now && $now-$pending['created'] < 600;
}
function googleClaimsFromExchange(string $idToken, string $client, string $nonce, int $now): array {
    // Google documents that tokens obtained DIRECTLY via authenticated HTTPS code exchange
    // are trusted. This helper must never authenticate caller-supplied ID tokens.
    // https://developers.google.com/identity/openid-connect/openid-connect#obtainuserinfo
    if (strlen($idToken)>16384) throw new RuntimeException('Invalid identity token');
    $parts=explode('.',$idToken);
    if (count($parts)!==3 || !preg_match('/^[A-Za-z0-9_-]+$/D',$parts[1])) throw new RuntimeException('Invalid identity token');
    $decoded=base64_decode(strtr($parts[1],'-_','+/'),true);
    $c=json_decode($decoded === false ? '' : $decoded,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($c) || !in_array($c['iss'] ?? '',['https://accounts.google.com','accounts.google.com'],true)
        || ($c['aud'] ?? null)!==$client || (isset($c['azp']) && $c['azp']!==$client)
        || !is_int($c['exp'] ?? null) || $c['exp']<=$now || !is_int($c['iat'] ?? null) || $c['iat']>$now+60
        || !is_string($c['nonce'] ?? null) || !hash_equals($nonce,$c['nonce'])
        || !is_string($c['sub'] ?? null) || !preg_match('/^[\x21-\x7E]{1,255}$/D',$c['sub'])
        || !in_array($c['email_verified'] ?? false,[true,'true'],true)
        || !is_string($c['email'] ?? null) || strlen($c['email'])>150 || !filter_var($c['email'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid identity claims');
    return $c;
}
function googleUser(PDO $db, array $claims): array {
    $q=$db->prepare('SELECT u.id,u.firstname,u.lastname,u.email,u.role FROM google_identities g JOIN users u ON u.id=g.user_id WHERE g.google_sub=?');
    $q->execute([$claims['sub']]);
    if ($user=$q->fetch()) return $user;
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$claims['email']]);
        if ($q->fetch()) throw new GoogleAccountConflict('Explicit account linking required');
        $first=is_string($claims['given_name'] ?? null) ? $claims['given_name'] : 'Google';
        $last=is_string($claims['family_name'] ?? null) ? $claims['family_name'] : 'User';
        // No password is exposed or chosen by the Google user; only a random unusable hash.
        $q=$db->prepare('INSERT INTO users (firstname,lastname,email,password_hash,role) VALUES (?,?,?,?,\'user\')');
        $q->execute([iconv_substr($first,0,100,'UTF-8'),iconv_substr($last,0,100,'UTF-8'),$claims['email'],password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
        $id=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO google_identities (google_sub,user_id) VALUES (?,?)');$q->execute([$claims['sub'],$id]);
        $db->commit();return ['id'=>$id,'firstname'=>$first,'lastname'=>$last,'email'=>$claims['email'],'role'=>'user'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') {
            $q=$db->prepare('SELECT u.id,u.firstname,u.lastname,u.email,u.role FROM google_identities g JOIN users u ON u.id=g.user_id WHERE g.google_sub=?');$q->execute([$claims['sub']]);
            if ($user=$q->fetch()) return $user;
            throw new GoogleAccountConflict('Explicit account linking required');
        }
        throw $e;
    }
}
