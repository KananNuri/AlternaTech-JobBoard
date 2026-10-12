<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/integration/common.php';
if (PHP_SAPI!=='cli' || getenv('INTEGRATION_TEST_DB')!=='1' || !str_ends_with(envValue('DB_NAME'),'_test')) { fwrite(STDERR,"Use only a fresh disposable *_test database with INTEGRATION_TEST_DB=1.\n"); exit(2); }
$password=getenv('TEST_FIXTURE_PASSWORD');
if (!$password || strlen($password)<12) { fwrite(STDERR,"Set TEST_FIXTURE_PASSWORD to a test-only password.\n");exit(2); }
$db=database();
foreach (['users','people','applications','company_members'] as $table) if ((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0) throw new RuntimeException('Authorization fixtures require empty account/profile/application/membership tables');
$db->beginTransaction();
try {
    $db->exec("UPDATE companies SET name='TechNova' WHERE id=1");
    foreach ([1=>['Alice','alice@example.invalid',1],2=>['Candidate','candidate@example.invalid',2],3=>['Bob','bob@example.invalid',3]] as $id=>[$name,$email,$person]) {
        $q=$db->prepare("INSERT INTO users (id,firstname,lastname,email,password_hash,role) VALUES (?,?,?,?,?,'user')");$q->execute([$id,$name,'Test',$email,password_hash($password,PASSWORD_DEFAULT)]);
        $q=$db->prepare('INSERT INTO people (id,user_id,firstname,lastname,email) VALUES (?,?,?,?,?)');$q->execute([$person,$id,$name,'Test',$email]);
    }
    $db->exec("INSERT INTO company_members (company_id,person_id,role) VALUES (1,1,'recruiter')");
    $db->exec("INSERT INTO applications (id,advertisement_id,person_id,message,status) VALUES (3,1,1,'Synthetic Charles authorization fixture','pending')");
    $db->commit();echo "PASS fixed Alice/Bob/TechNova/application-3 fixtures for Charles's unchanged test\n";
} catch (Throwable $e) { $db->rollBack();throw $e; }
