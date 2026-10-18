<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
methods(['GET']);
try {
    $user=currentUser(); $db=database(); [$page,$size,$offset]=pagination();
    $values=[$user['person_id']];
    $from=" FROM advertisements a JOIN companies c ON c.id=a.company_id LEFT JOIN categories cat ON cat.id=a.category_id JOIN company_members cm ON cm.company_id=a.company_id WHERE cm.person_id=? AND cm.role='recruiter'";
    if (isset($_GET['company_id'])) { $from.=' AND a.company_id=?'; $values[]=positiveId($_GET['company_id']); }
    $q=$db->prepare('SELECT COUNT(*)'.$from); $q->execute($values); $total=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT a.*,c.name AS company,cat.name AS category'.$from.' ORDER BY a.id DESC LIMIT '.$size.' OFFSET '.$offset); $q->execute($values);
    reply(200,['items'=>$q->fetchAll(),'page'=>$page,'per_page'=>$size,'total'=>$total,'pages'=>max(1,(int)ceil($total/$size))]);
} catch (Throwable $e) { dbFailure($e); }
