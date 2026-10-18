<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

function applicationUrl(): ?string {
    $url=rtrim(envValue('APP_URL'),'/'); $parts=parse_url($url);
    if (!filter_var($url,FILTER_VALIDATE_URL) || !$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) return null;
    if (($parts['scheme']??'')!=='https' && !(($parts['scheme']??'')==='http' && in_array($parts['host']??'',['127.0.0.1','localhost'],true))) return null;
    return $url;
}
function mailEnabled(): bool {
    return envValue('SMTP_HOST')!=='' && filter_var(envValue('MAIL_FROM'),FILTER_VALIDATE_EMAIL)!==false && applicationUrl()!==null;
}
function queueMail(PDO $db, string $recipient, string $subject, string $body, int $lifetime=86400): void {
    if (!mailEnabled()) return;
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || strlen($recipient)>150 || preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Invalid mail envelope');
    $q=$db->prepare('INSERT INTO mail_outbox (recipient,subject,body,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))');
    $q->execute([$recipient,$subject,$body,$lifetime]);
}
function issueAccountToken(PDO $db, array $user, string $purpose): void {
    if (!mailEnabled()) return;
    if (!in_array($purpose,['reset','verify'],true)) throw new RuntimeException('Invalid token purpose');
    $token=bin2hex(random_bytes(32)); $seconds=$purpose==='reset'?1800:86400;
    $db->prepare('DELETE FROM account_tokens WHERE user_id=? AND purpose=?')->execute([$user['id'],$purpose]);
    $db->prepare('INSERT INTO account_tokens (token_hash,user_id,purpose,email,expires_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))')->execute([hash('sha256',$token),$user['id'],$purpose,$user['email'],$seconds]);
    // Fragment tokens stay out of HTTP access logs and Referer headers. The browser submits them by POST.
    $link=applicationUrl().'/security.php#'.http_build_query(['action'=>$purpose,'token'=>$token]);
    queueMail($db,$user['email'],$purpose==='reset'?'Reset your AlternaTech password':'Verify your AlternaTech email',($purpose==='reset'?'Choose a new password':'Confirm your email address').":\n\n".$link."\n\nThis link expires in ".($seconds/60)." minutes. Ignore it if you did not request this.",$seconds);
}
function notifyApplication(PDO $db, int $adId): void {
    if (!mailEnabled()) return;
    $q=$db->prepare("SELECT DISTINCT u.email FROM users u JOIN people p ON p.user_id=u.id JOIN company_members cm ON cm.person_id=p.id JOIN advertisements a ON a.company_id=cm.company_id WHERE a.id=? AND cm.role='recruiter' AND u.email_verified_at IS NOT NULL"); $q->execute([$adId]);
    foreach ($q->fetchAll() as $row) queueMail($db,$row['email'],'New AlternaTech application',"A new application is available in your recruiter space.\n\n".applicationUrl().'/');
}
function notifyApplicationStatus(PDO $db, int $applicationId, string $status): void {
    if (!mailEnabled()) return;
    $q=$db->prepare('SELECT u.email FROM applications a JOIN people p ON p.id=a.person_id JOIN users u ON u.id=p.user_id WHERE a.id=? AND u.email_verified_at IS NOT NULL'); $q->execute([$applicationId]);
    if ($row=$q->fetch()) queueMail($db,$row['email'],'AlternaTech application update',"An application status has changed to $status. View your applications:\n\n".applicationUrl().'/');
}
