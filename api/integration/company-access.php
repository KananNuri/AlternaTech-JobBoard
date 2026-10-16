<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

function accessRows(PDO $db,string $sql,array $args=[]): array {
    $q=$db->prepare($sql);$q->execute($args);return $q->fetchAll();
}
function accessFail(PDO $db,int $status,string $error): never {
    if ($db->inTransaction()) $db->rollBack();reply($status,['error'=>$error]);
}
function lockCompany(PDO $db,int $id): void {
    if (!accessRows($db,'SELECT id FROM companies WHERE id=? FOR UPDATE',[$id])) accessFail($db,404,'Company not found');
}
function canManage(PDO $db,array $user,int $company): bool {
    return $user['role']==='admin' || (bool)accessRows($db,'SELECT m.person_id FROM company_managers m JOIN people p ON p.id=m.person_id WHERE m.company_id=? AND m.person_id=? AND p.user_id=?',[$company,$user['person_id'],$user['id']]);
}
function grantRecruiter(PDO $db,int $company,int $person): void {
    $q=$db->prepare("INSERT INTO company_members(company_id,person_id,role) VALUES (?,?,'recruiter') ON DUPLICATE KEY UPDATE role='recruiter'");$q->execute([$company,$person]);
}
function appointManager(PDO $db,int $company,int $person,int $admin): void {
    grantRecruiter($db,$company,$person);
    $q=$db->prepare('INSERT INTO company_managers(company_id,person_id,appointed_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE person_id=VALUES(person_id),appointed_by=VALUES(appointed_by),appointed_at=CURRENT_TIMESTAMP');$q->execute([$company,$person,$admin]);
}
try {
    $user=currentUser();$db=database();$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
    $method=methods(['GET','POST','DELETE']);
    if ($method==='GET' && $path==='/api/company-access') {
        $companies=accessRows($db,'SELECT c.id,c.name,(m.person_id=cm.person_id) AS is_manager FROM company_members cm JOIN companies c ON c.id=cm.company_id LEFT JOIN company_managers m ON m.company_id=c.id WHERE cm.person_id=? ORDER BY c.name LIMIT 100',[$user['person_id']]);
        $proposals=accessRows($db,'SELECT id,name,status,company_id,created_at FROM company_proposals WHERE requester_id=? ORDER BY id DESC LIMIT 100',[$user['id']]);
        $requests=accessRows($db,'SELECT r.id,r.company_id,c.name,r.status,r.created_at FROM company_join_requests r JOIN companies c ON c.id=r.company_id WHERE r.person_id=? ORDER BY r.id DESC LIMIT 100',[$user['person_id']]);
        $inbox=accessRows($db,"SELECT r.id,r.company_id,c.name AS company,p.firstname,p.lastname,p.email,r.message FROM company_join_requests r JOIN companies c ON c.id=r.company_id JOIN people p ON p.id=r.person_id JOIN company_managers m ON m.company_id=c.id WHERE m.person_id=? AND r.status='pending' AND p.user_id IS NOT NULL ORDER BY r.id LIMIT 100",[$user['person_id']]);
        $members=accessRows($db,'SELECT cm.company_id,c.name AS company,p.id AS person_id,p.firstname,p.lastname,p.email,(m.person_id=cm.person_id) AS is_manager FROM company_members cm JOIN companies c ON c.id=cm.company_id JOIN people p ON p.id=cm.person_id JOIN company_managers m ON m.company_id=c.id WHERE m.person_id=? ORDER BY c.name,p.id LIMIT 100',[$user['person_id']]);
        $pending=$user['role']==='admin'?accessRows($db,"SELECT r.*,u.firstname,u.lastname FROM company_proposals r JOIN users u ON u.id=r.requester_id WHERE r.status='pending' ORDER BY r.id LIMIT 100"):[];
        reply(200,['user'=>$user,'companies'=>$companies,'proposals'=>$proposals,'requests'=>$requests,'inbox'=>$inbox,'members'=>$members,'pending_proposals'=>$pending,'limit'=>100]);
    }
    if ($method==='GET' && $path==='/api/company-access/search') {
        $q=$_GET['q']??'';if (!is_string($q) || mb_strlen(trim($q))<2 || mb_strlen($q)>150) reply(422,['error'=>'Enter 2 to 150 characters']);
        $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($q)).'%';
        reply(200,['companies'=>accessRows($db,"SELECT c.id,c.name,(m.person_id IS NOT NULL) AS has_manager FROM companies c LEFT JOIN company_managers m ON m.company_id=c.id WHERE c.name LIKE ? ESCAPE '!' ORDER BY c.name,c.id LIMIT 30",[$term])]);
    }
    if ($method==='GET') reply(404,['error'=>'Route not found']);
    csrf();$body=$method==='DELETE'?[]:jsonBody(12000);
    if ($path==='/api/company-access/proposals' && $method==='POST') {
        if (array_diff(array_keys($body),['name','email','website','registration_number','evidence'])) reply(422,['error'=>'Unsupported company fields']);
        $name=textField($body,'name',150);$email=textField($body,'email',150);$website=textField($body,'website',255,false);$number=textField($body,'registration_number',50,false);$evidence=textField($body,'evidence',2000);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) reply(422,['error'=>'Valid email required']);
        if ($website && (!filter_var($website,FILTER_VALIDATE_URL) || !in_array(parse_url($website,PHP_URL_SCHEME),['http','https'],true))) reply(422,['error'=>'Valid website required']);
        $db->beginTransaction();accessRows($db,'SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
        if (accessRows($db,"SELECT id FROM company_proposals WHERE requester_id=? AND status='pending'",[$user['id']])) accessFail($db,409,'You already have a pending company request');
        $q=$db->prepare('INSERT INTO company_proposals(requester_id,name,email,website,registration_number,evidence) VALUES (?,?,?,?,?,?)');$q->execute([$user['id'],$name,$email,$website,$number,$evidence]);$id=(int)$db->lastInsertId();$db->commit();reply(201,['id'=>$id]);
    }
    if (preg_match('~^/api/company-access/proposals/([1-9][0-9]*)/decision$~',$path,$match) && $method==='POST') {
        adminUser();$id=positiveId($match[1]);
        if (array_keys($body)!==['decision'] || !in_array($body['decision'],['approve','reject'],true)) reply(422,['error'=>'Choose approve or reject']);
        $db->beginTransaction();$rows=accessRows($db,'SELECT * FROM company_proposals WHERE id=? FOR UPDATE',[$id]);
        if (!$rows) accessFail($db,404,'Request not found');$r=$rows[0];if ($r['status']!=='pending') accessFail($db,409,'Request already decided');
        $company=null;
        if ($body['decision']==='approve') {
            $people=accessRows($db,'SELECT id FROM people WHERE user_id=? FOR UPDATE',[$r['requester_id']]);if (!$people) accessFail($db,409,'Applicant account has no linked profile');
            $q=$db->prepare('INSERT INTO companies(name,email,website) VALUES (?,?,?)');$q->execute([$r['name'],$r['email'],$r['website']]);$company=(int)$db->lastInsertId();
            appointManager($db,$company,(int)$people[0]['id'],(int)$user['id']);
        }
        $q=$db->prepare('UPDATE company_proposals SET status=?,company_id=?,decided_by=?,decided_at=NOW() WHERE id=?');$q->execute([$body['decision']==='approve'?'approved':'rejected',$company,$user['id'],$id]);$db->commit();reply(200,['company_id'=>$company]);
    }
    if (preg_match('~^/api/company-access/companies/([1-9][0-9]*)/(join|manager)$~',$path,$match) && $method==='POST') {
        $company=positiveId($match[1]);$action=$match[2];if ($action==='manager') adminUser();
        if (array_keys($body)!==[$action==='join'?'message':'email']) reply(422,['error'=>'Unsupported request fields']);
        $value=textField($body,$action==='join'?'message':'email',$action==='join'?2000:150);
        $db->beginTransaction();lockCompany($db,$company);
        if ($action==='manager') {
            $people=accessRows($db,'SELECT p.id FROM people p JOIN users u ON u.id=p.user_id WHERE u.email=? FOR UPDATE',[$value]);
            if (!$people) accessFail($db,404,'Existing account not found');appointManager($db,$company,(int)$people[0]['id'],(int)$user['id']);
        } else {
            if (accessRows($db,'SELECT person_id FROM company_members WHERE company_id=? AND person_id=?',[$company,$user['person_id']])) accessFail($db,409,'You are already a recruiter for this company');
            $rows=accessRows($db,'SELECT status FROM company_join_requests WHERE company_id=? AND person_id=?',[$company,$user['person_id']]);
            if ($rows && $rows[0]['status']==='pending') accessFail($db,409,'You already have a pending join request');
            $q=$db->prepare("INSERT INTO company_join_requests(company_id,person_id,message) VALUES (?,?,?) ON DUPLICATE KEY UPDATE message=VALUES(message),status='pending',created_at=CURRENT_TIMESTAMP,decided_by=NULL,decided_at=NULL");$q->execute([$company,$user['person_id'],$value]);
        }
        $db->commit();reply(200,['message'=>'Request saved']);
    }
    if (preg_match('~^/api/company-access/joins/([1-9][0-9]*)/decision$~',$path,$match) && $method==='POST') {
        $id=positiveId($match[1]);if (array_keys($body)!==['decision'] || !in_array($body['decision'],['approve','reject'],true)) reply(422,['error'=>'Choose approve or reject']);
        $db->beginTransaction();$rows=accessRows($db,'SELECT company_id FROM company_join_requests WHERE id=?',[$id]);if (!$rows) accessFail($db,404,'Request not found');$company=(int)$rows[0]['company_id'];lockCompany($db,$company);
        if (!canManage($db,$user,$company)) accessFail($db,403,'Company manager access required');
        $r=accessRows($db,'SELECT * FROM company_join_requests WHERE id=? FOR UPDATE',[$id])[0]??null;
        if (!$r || $r['status']!=='pending') accessFail($db,409,'Request already decided');
        if (!accessRows($db,'SELECT id FROM people WHERE id=? AND user_id IS NOT NULL FOR UPDATE',[$r['person_id']])) accessFail($db,409,'Applicant account no longer exists');
        if ($body['decision']==='approve') grantRecruiter($db,$company,(int)$r['person_id']);
        $q=$db->prepare('UPDATE company_join_requests SET status=?,decided_by=?,decided_at=NOW() WHERE id=?');$q->execute([$body['decision']==='approve'?'approved':'rejected',$user['id'],$id]);$db->commit();reply(200,['message'=>'Request decided']);
    }
    if (preg_match('~^/api/company-access/companies/([1-9][0-9]*)/members/([1-9][0-9]*)$~',$path,$match) && $method==='DELETE') {
        $company=positiveId($match[1]);$person=positiveId($match[2]);$db->beginTransaction();lockCompany($db,$company);
        if (!canManage($db,$user,$company)) accessFail($db,403,'Company manager access required');
        if (accessRows($db,'SELECT person_id FROM company_managers WHERE company_id=? AND person_id=?',[$company,$person])) accessFail($db,409,'Assign another manager before removing this recruiter');
        $q=$db->prepare('DELETE FROM company_members WHERE company_id=? AND person_id=?');$q->execute([$company,$person]);if (!$q->rowCount()) accessFail($db,404,'Membership not found');$db->commit();reply(204);
    }
    reply(404,['error'=>'Route not found']);
} catch(Throwable $e) {if (isset($db) && $db->inTransaction()) $db->rollBack();dbFailure($e);}
