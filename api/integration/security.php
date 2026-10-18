<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
require_once __DIR__.'/rate-limit.php';
require_once __DIR__.'/mail.php';
methods(['POST']); csrf(); $body=jsonBody(8192); $path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
try {
    $db=database();
    if ($path==='/api/auth/password/request') {
        $email=textField($body,'email',150);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) reply(422,['error'=>'Valid email required']);
        accountRateLimits($db,'reset-request',$email);
        if (!mailEnabled()) reply(503,['error'=>'Email delivery is not configured. Contact the site administrator.']);
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,email FROM users WHERE email=? FOR UPDATE'); $q->execute([$email]);
        if ($user=$q->fetch()) issueAccountToken($db,$user,'reset');
        $db->commit();
        reply(200,['message'=>'If this account exists, a password reset link will be emailed.']);
    }
    if ($path==='/api/auth/email/request') {
        $user=currentUser(); accountRateLimits($db,'verify-request',$user['email']);
        if (!mailEnabled()) reply(503,['error'=>'Email delivery is not configured. Contact the site administrator.']);
        if (!$user['email_verified_at']) {
            $db->beginTransaction(); $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
            issueAccountToken($db,$user,'verify'); $db->commit();
        }
        reply(200,['message'=>'If verification is needed, a link will be emailed.']);
    }
    if (!in_array($path,['/api/auth/password/reset','/api/auth/email/verify'],true)) reply(404,['error'=>'Route not found']);
    rateLimit($db,'account-token:ip',$_SERVER['REMOTE_ADDR']??'unknown',30,900);
    $token=$body['token']??null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) reply(422,['error'=>'Invalid or expired link']);
    $purpose=$path==='/api/auth/password/reset'?'reset':'verify'; $hash=hash('sha256',$token);
    if ($purpose==='reset') {
        $password=$body['password']??null;
        if (!is_string($password) || strlen($password)<12 || strlen($password)>72) reply(422,['error'=>'Password must contain 12 to 72 bytes']);
    }
    $db->beginTransaction();
    $q=$db->prepare('SELECT t.user_id,t.email FROM account_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose=? AND t.expires_at>UTC_TIMESTAMP() AND t.email=u.email FOR UPDATE'); $q->execute([$hash,$purpose]); $tokenRow=$q->fetch();
    if (!$tokenRow) { $db->rollBack(); reply(422,['error'=>'Invalid or expired link']); }
    if ($purpose==='reset') {
        $db->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$tokenRow['user_id']]);
        $db->prepare('DELETE FROM account_tokens WHERE user_id=?')->execute([$tokenRow['user_id']]);
    } else {
        $db->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?')->execute([$tokenRow['user_id']]);
        $db->prepare('DELETE FROM account_tokens WHERE token_hash=?')->execute([$hash]);
    }
    $db->commit(); reply(200,['message'=>$purpose==='reset'?'Password updated. Sign in again on your devices.':'Email address verified.']);
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); dbFailure($e); }
