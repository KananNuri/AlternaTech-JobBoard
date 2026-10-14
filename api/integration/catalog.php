<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
methods(['GET']);
try {
    $path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'); $db=database();
    if ($path==='/api/categories') reply(200,$db->query('SELECT id,name FROM categories ORDER BY name')->fetchAll());
    [$page,$size,$offset]=pagination(); $where=[]; $values=[];
    if (isset($GLOBALS['route_company_id'])) {
        $company=positiveId($GLOBALS['route_company_id']); $q=$db->prepare('SELECT id FROM companies WHERE id=?'); $q->execute([$company]);
        if (!$q->fetch()) reply(404,['error'=>'Company not found']);
        $where[]='a.company_id=?'; $values[]=$company;
    }
    if (isset($_GET['q'])) { $search=textField($_GET,'q',200,false); if ($search) { $where[]='(a.title LIKE ? ESCAPE \'!\' OR a.description LIKE ? ESCAPE \'!\' OR c.name LIKE ? ESCAPE \'!\')'; array_push($values,...array_fill(0,3,'%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%')); } }
    if (isset($_GET['location']) && $_GET['location']!=='') { $where[]="a.location LIKE ? ESCAPE '!'"; $values[]='%'.strtr(textField($_GET,'location',150),['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; }
    if (isset($_GET['category_id']) && $_GET['category_id']!=='') { $where[]='a.category_id=?'; $values[]=positiveId($_GET['category_id']); }
    $from=' FROM advertisements a JOIN companies c ON c.id=a.company_id LEFT JOIN categories cat ON cat.id=a.category_id';
    $clause=$where?' WHERE '.implode(' AND ',$where):'';
    $q=$db->prepare('SELECT COUNT(*)'.$from.$clause); $q->execute($values); $total=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT a.*,c.name AS company,cat.name AS category'.$from.$clause.' ORDER BY a.id DESC LIMIT '.$size.' OFFSET '.$offset); $q->execute($values);
    reply(200,['items'=>$q->fetchAll(),'page'=>$page,'per_page'=>$size,'total'=>$total,'pages'=>max(1,(int)ceil($total/$size))]);
} catch (Throwable $e) { dbFailure($e); }
