<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/auth/google/functions.php';
if(getenv('GOOGLE_TEST_DB')!=='1'){fwrite(STDERR,"Set GOOGLE_TEST_DB=1 only for a disposable test database.\n");exit(2);}
$db=database();$prefix='google-test-'.bin2hex(random_bytes(8));$claims=['sub'=>$prefix,'email'=>$prefix.'@example.invalid','given_name'=>'Test','family_name'=>'Google'];$ids=[];
try{
 $user=googleUser($db,$claims);$ids[]=$user['id'];if($user['role']!=='user')throw new RuntimeException('Role escalation');echo "PASS Google user creation and user role\n";
 $same=googleUser($db,$claims);if($same['id']!=$user['id'])throw new RuntimeException('Duplicate identity');echo "PASS repeated login identity\n";
 $changed=googleUser($db,array_replace($claims,['email'=>$prefix.'-changed@example.invalid']));if($changed['id']!=$user['id'])throw new RuntimeException('Email used as identity');echo "PASS stable subject after email change\n";
 $q=$db->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$user['id']]);$hash=$q->fetchColumn();if(!password_get_info($hash)['algo'])throw new RuntimeException('Missing password hash');echo "PASS random password stored as hash\n";
 $blocked=false;try{googleUser($db,array_replace($claims,['sub'=>$prefix.'-other']));}catch(GoogleAccountConflict){$blocked=true;}if(!$blocked)throw new RuntimeException('Automatic email link');echo "PASS existing email refuses automatic linking\n";
 $db->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$user['id']]);$same=googleUser($db,$claims);if($same['role']!=='admin')throw new RuntimeException('Existing role lost');echo "PASS existing role read from DB\n";
}finally{foreach($ids as $id)$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
$q=$db->prepare('SELECT COUNT(*) FROM google_identities WHERE google_sub=?');$q->execute([$prefix]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('Identity cascade failed');echo "PASS identity cascade and cleanup\n";
