<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/integration/common.php';
require dirname(__DIR__).'/api/auth/google/functions.php';
if (PHP_SAPI!=='cli' || getenv('INTEGRATION_TEST_DB')!=='1' || !str_ends_with((string)database()->query('SELECT DATABASE()')->fetchColumn(),'_test')) throw new RuntimeException('Disposable *_test database required');
$db=database(); $action=$argv[1]??''; $email=$argv[2]??'';
if ($action==='clear-limits') { $db->exec('DELETE FROM auth_rate_limits'); echo '{}'; exit; }
if (!preg_match('/^security-[a-f0-9]+-[a-z]+@example\.invalid$/D',$email)) throw new RuntimeException('Synthetic email required');
$q=$db->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $user=$q->fetch();
if ($action==='token') {
    $q=$db->prepare('SELECT body FROM mail_outbox WHERE recipient=? AND subject=? ORDER BY id DESC LIMIT 1'); $q->execute([$email,($argv[3]??'')==='reset'?'Reset your AlternaTech password':'Verify your AlternaTech email']);
    preg_match('/token=([a-f0-9]{64})/',$q->fetchColumn()?:'',$match); echo json_encode(['token'=>$match[1]??null]);
} elseif ($action==='recruiter') {
    $person=profileFor($db,$user); $db->prepare("INSERT INTO company_members (company_id,person_id,role) VALUES (1,?,'recruiter')")->execute([$person]);
    $db->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]); echo '{}';
} elseif ($action==='expire') {
    $db->prepare('UPDATE account_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id=?')->execute([$user['id']]); echo '{}';
} elseif ($action==='mail-count') {
    $q=$db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE recipient=? AND subject=?'); $q->execute([$email,$argv[3]??'']); echo json_encode(['count'=>(int)$q->fetchColumn()]);
} elseif ($action==='google-link') {
    $claims=['sub'=>'security-'.$user['id'],'email'=>$email]; linkGoogleUser($db,$user,$claims); linkGoogleUser($db,$user,$claims);
    $q=$db->prepare('SELECT COUNT(*) FROM google_identities WHERE user_id=?'); $q->execute([$user['id']]); if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Link is not idempotent');
    $blocked=false; try { linkGoogleUser($db,$user,array_replace($claims,['sub'=>'security-other-'.$user['id']])); } catch (GoogleAccountConflict) { $blocked=true; } if (!$blocked) throw new RuntimeException('Existing link replaced');
    $q=$db->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$argv[3]??'']); $other=$q->fetch();
    $blocked=false;try{linkGoogleUser($db,$other,$claims);}catch(GoogleAccountConflict){$blocked=true;}if(!$blocked)throw new RuntimeException('Identity reassigned to another account');
    $blocked=false;try{linkGoogleUser($db,$user,$claims,(int)$user['auth_version']+1);}catch(GoogleAccountConflict){$blocked=true;}if(!$blocked)throw new RuntimeException('Stale authentication allowed');
    echo '{"checks":4}';
} elseif ($action==='cleanup') {
    $db->prepare('DELETE FROM mail_outbox WHERE recipient=?')->execute([$email]);
    if ($user) { $db->prepare('DELETE FROM users WHERE id=?')->execute([$user['id']]); $db->prepare('DELETE FROM people WHERE email=? AND user_id IS NULL')->execute([$email]); } echo '{}';
} else throw new RuntimeException('Unknown fixture action');
