<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

function resources(): array {
    // Explicit schema: no table/column names ever come from unchecked input.
    return [
        'companies'=>['name'=>['text',150,true],'description'=>['text',20000,false],'email'=>['email',150,false],'phone'=>['text',30,false],'website'=>['url',255,false]],
        'categories'=>['name'=>['text',100,true]],
        'users'=>['firstname'=>['text',100,true],'lastname'=>['text',100,true],'email'=>['email',150,true],'phone'=>['text',30,false],'password'=>['password',72,true],'role'=>['enum',['user','admin'],true]],
        'people'=>['user_id'=>['id',null,false],'firstname'=>['text',100,true],'lastname'=>['text',100,true],'email'=>['email',150,true],'phone'=>['text',30,false]],
        'advertisements'=>['company_id'=>['id',null,true],'category_id'=>['id',null,false],'title'=>['text',200,true],'short_description'=>['text',500,true],'description'=>['text',16000,true],'salary'=>['text',100,false],'location'=>['text',150,true],'working_time'=>['text',100,false],'contract_type'=>['text',100,false]],
        'applications'=>['advertisement_id'=>['id',null,true],'person_id'=>['id',null,true],'message'=>['text',20000,false],'status'=>['enum',['pending','reviewed','accepted','rejected'],true]],
        'company_members'=>['company_id'=>['id',null,true],'person_id'=>['id',null,true],'role'=>['enum',['recruiter'],true]],
        'google_identities'=>['google_sub'=>['subject',255,true],'user_id'=>['id',null,true]],
    ];
}
function adminFields(array $body,array $spec,bool $partial): array {
    if (!$body || array_diff(array_keys($body),array_keys($spec))) reply(422,['error'=>'Unsupported or empty record fields']);
    $out=[];
    foreach ($spec as $key=>[$type,$limit,$required]) {
        if (!array_key_exists($key,$body)) {
            if (!$partial && $required) reply(422,['error'=>"Required field: $key"]);
            continue;
        }
        $v=$body[$key];
        if (!$required && ($v===null || $v==='')) { $out[$key]=null; continue; }
        if ($type==='id') { if (!is_int($v) || $v<1 || $v>4294967295) reply(422,['error'=>"Invalid $key"]); }
        elseif ($type==='enum') { if (!is_string($v) || !in_array($v,$limit,true)) reply(422,['error'=>"Invalid $key"]); }
        elseif ($type==='password') { if (!is_string($v) || strlen($v)<12 || strlen($v)>72) reply(422,['error'=>'Password must contain 12 to 72 bytes']); $key='password_hash'; $v=password_hash($v,PASSWORD_DEFAULT); }
        else {
            $v=textField($body,$key,$limit,$required);
            if ($type==='email' && !filter_var($v,FILTER_VALIDATE_EMAIL)) reply(422,['error'=>"Invalid $key"]);
            if ($type==='subject' && !preg_match('/^[\x21-\x7E]{1,255}$/D',$v)) reply(422,['error'=>'Invalid Google subject']);
            if ($type==='url' && (!filter_var($v,FILTER_VALIDATE_URL) || !in_array(parse_url($v,PHP_URL_SCHEME),['http','https'],true))) reply(422,['error'=>"Invalid $key"]);
        }
        $out[$key]=$v;
    }
    return $out;
}
try {
    $path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
    $specs=resources(); $user=adminUser(); $db=database();
    if ($path==='/api/admin') { methods(['GET']); reply(200,['resources'=>$specs]); }
    if (!preg_match('~^/api/admin/([a-z_]+)(?:/([^/]+))?$~',$path,$m) || !isset($specs[$m[1]])) reply(404,['error'=>'Resource not found']);
    $table=$m[1]; $key=isset($m[2])?rawurldecode($m[2]):null; $method=methods($key===null?['GET','POST']:['GET','PATCH','PUT','DELETE']);
    // Composite membership key is company_id:person_id; identity keys are opaque subjects.
    if ($key!==null && $table==='company_members') {
        $parts=explode(':',$key); if (count($parts)!==2) reply(422,['error'=>'Use company_id:person_id']);
        $where='company_id=? AND person_id=?'; $ids=[positiveId($parts[0]),positiveId($parts[1])];
    } elseif ($key!==null && $table==='google_identities') {
        if (!preg_match('/^[\x21-\x7E]{1,255}$/D',$key)) reply(422,['error'=>'Invalid identity']);
        $where='google_sub=?'; $ids=[$key];
    } else { $where='id=?'; $ids=$key!==null?[positiveId($key)]:[]; }
    // Explicit lists: credentials never leave the server, including legacy people.password kept by the migration.
    $columns=match($table){'users'=>'id,firstname,lastname,email,phone,role,created_at,updated_at','people'=>'id,user_id,firstname,lastname,email,phone,created_at',default=>'*'};
    if ($method==='GET' && $key===null) {
        [$page,$size,$offset]=pagination(); $total=(int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        $order=$table==='company_members'?'company_id,person_id':($table==='google_identities'?'google_sub':'id DESC');
        reply(200,['items'=>$db->query("SELECT $columns FROM $table ORDER BY $order LIMIT $size OFFSET $offset")->fetchAll(),'page'=>$page,'per_page'=>$size,'total'=>$total,'pages'=>max(1,(int)ceil($total/$size))]);
    }
    if ($method==='GET') { $q=$db->prepare("SELECT $columns FROM $table WHERE $where"); $q->execute($ids); $row=$q->fetch(); if (!$row) reply(404,['error'=>'Record not found']); reply(200,$row); }
    csrf();
    $data=$method==='DELETE'?[]:adminFields(jsonBody(),$specs[$table],$method==='PATCH');
    // A PUT replaces optional fields too; never silently leave stale values.
    if ($method==='PUT') foreach ($specs[$table] as $field=>[$type,$limit,$required]) if (!$required && !array_key_exists($field,$data)) $data[$field]=null;
    $db->beginTransaction();
    if ($key!==null) {
        $q=$db->prepare("SELECT $columns FROM $table WHERE $where FOR UPDATE"); $q->execute($ids); $row=$q->fetch();
        if (!$row) { $db->rollBack(); reply(404,['error'=>'Record not found']); }
        if ($table==='users' && ($method==='DELETE' || (isset($data['role']) && $data['role']!=='admin'))) {
            if ((int)$row['id']===(int)$user['id']) { $db->rollBack(); reply(409,['error'=>'Cannot delete or demote your active administrator account']); }
            $admins=$db->query("SELECT id FROM users WHERE role='admin' FOR UPDATE")->fetchAll();
            if ($row['role']==='admin' && count($admins)<2) { $db->rollBack(); reply(409,['error'=>'At least one administrator is required']); }
        }
        if ($table==='people' && $row['user_id']!==null && ($method==='DELETE' || array_key_exists('user_id',$data))) { $db->rollBack(); reply(409,['error'=>'Manage linked accounts through users; the profile remains linked']); }
        if ($table==='people' && $row['user_id']!==null) {
            $q=$db->prepare('UPDATE users SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($data))).' WHERE id=?'); $q->execute([...array_values($data),$row['user_id']]);
        }
    }
    if ($method==='DELETE') { $q=$db->prepare("DELETE FROM $table WHERE $where"); $q->execute($ids); $db->commit(); reply(204); }
    if ($method==='POST') {
        $fields=array_keys($data); $q=$db->prepare("INSERT INTO $table (".implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')'); $q->execute(array_values($data));
        $key=$table==='company_members'?$data['company_id'].':'.$data['person_id']:($table==='google_identities'?$data['google_sub']:(int)$db->lastInsertId());
    } else {
        if (!$data) { $db->rollBack(); reply(422,['error'=>'No changes supplied']); }
        $q=$db->prepare("UPDATE $table SET ".implode(',',array_map(fn($k)=>"$k=?",array_keys($data)))." WHERE $where"); $q->execute([...array_values($data),...$ids]);
        $updated=array_replace($row,$data);
        if ($table==='company_members') $key=$updated['company_id'].':'.$updated['person_id'];
        if ($table==='google_identities') $key=$updated['google_sub'];
    }
    if ($table==='users') {
        $q=$db->prepare('SELECT id,firstname,lastname,email,phone,role FROM users WHERE id=?'); $q->execute([$key]); profileFor($db,$q->fetch());
    }
    $db->commit(); reply($method==='POST'?201:200,['key'=>$key,'message'=>'Record saved']);
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); dbFailure($e); }
