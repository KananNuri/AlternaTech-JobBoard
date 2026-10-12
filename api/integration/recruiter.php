<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
methods(['GET']);
try {
    $user=currentUser();$db=database();[$page,$size,$offset]=pagination();
    $from=" FROM applications a JOIN advertisements ad ON ad.id=a.advertisement_id JOIN companies c ON c.id=ad.company_id JOIN people p ON p.id=a.person_id JOIN company_members cm ON cm.company_id=c.id WHERE cm.person_id=? AND cm.role='recruiter'";
    $q=$db->prepare('SELECT COUNT(*)'.$from);$q->execute([$user['person_id']]);$total=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT a.id AS application_id,a.advertisement_id,a.person_id AS candidate_id,a.message,a.status,a.created_at,ad.title AS advertisement_title,c.name AS company,p.firstname,p.lastname,p.email,p.phone'.$from.' ORDER BY a.id DESC LIMIT '.$size.' OFFSET '.$offset);$q->execute([$user['person_id']]);
    reply(200,['applications'=>$q->fetchAll(),'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'total'=>$total]);
} catch (Throwable $e) { dbFailure($e); }
