<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/integration/common.php';
if (PHP_SAPI!=='cli' || getenv('INTEGRATION_TEST_DB')!=='1') { fwrite(STDERR,"Set INTEGRATION_TEST_DB=1 only for an isolated disposable database.\n"); exit(2); }
$db=database();
if (is_file(__DIR__.'/.runtime-fixtures.json')) {
    $old=json_decode(file_get_contents(__DIR__.'/.runtime-fixtures.json'),true,16,JSON_THROW_ON_ERROR);
    if (!preg_match('/^integration-[a-f0-9]{10}$/D',$old['prefix']??'')) throw new RuntimeException('Invalid fixture prefix');
    foreach (['users','people'] as $table) { $q=$db->prepare("DELETE FROM $table WHERE email LIKE ?"); $q->execute([$old['prefix'].'-%@example.invalid']); }
}
$prefix='integration-'.bin2hex(random_bytes(5));$password='Test-only-'.bin2hex(random_bytes(12));$fixtures=['password'=>$password,'prefix'=>$prefix];
// Deliberately separate account and profile IDs to catch accidental ID conflation.
$db->prepare('INSERT INTO people (firstname,lastname,email) VALUES (?,?,?)')->execute(['Offset','Profile',$prefix.'-offset@example.invalid']);
$fixtures['offset_person']=(int)$db->lastInsertId();
foreach (['admin','recruiter','outsider','candidate'] as $label) {
    $email=$prefix.'-'.$label.'@example.invalid';
    $q=$db->prepare('INSERT INTO users (firstname,lastname,email,password_hash,role) VALUES (?,?,?,?,?)');$q->execute(['Test',ucfirst($label),$email,password_hash($password,PASSWORD_DEFAULT),$label==='admin'?'admin':'user']);
    $id=(int)$db->lastInsertId();$user=['id'=>$id,'firstname'=>'Test','lastname'=>ucfirst($label),'email'=>$email];$person=profileFor($db,$user);
    $fixtures[$label]=['email'=>$email,'id'=>$id,'person_id'=>$person];
}
$q=$db->prepare("INSERT INTO company_members(company_id,person_id,role) VALUES (1,?,'recruiter')");$q->execute([$fixtures['recruiter']['person_id']]);
file_put_contents(__DIR__.'/.runtime-fixtures.json',json_encode($fixtures,JSON_THROW_ON_ERROR));
echo "PASS isolated fixtures prepared (account/profile IDs intentionally differ)\n";
