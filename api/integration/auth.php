<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
require_once dirname(__DIR__).'/auth/rate-limit.php';
$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
try {
    if ($path==='/api/auth/session') {
        methods(['GET']); $user=currentUser(false);
        reply(200,['user'=>$user,'csrf_token'=>$user?$_SESSION['csrf_token']:null]);
    }
    if ($path==='/api/auth/logout') {
        methods(['POST']); currentUser(); csrf(); $_SESSION=[];
        $p=session_get_cookie_params(); session_destroy();
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>$p['secure'],'httponly'=>true,'samesite'=>'Lax']); reply(200,['message'=>'Signed out']);
    }
    if ($path==='/api/account') {
        $method=methods(['GET','PATCH']); $user=currentUser();
        if ($method==='GET') reply(200,['user'=>$user]);
        csrf(); $body=jsonBody(8192);
        if (!$body || array_diff(array_keys($body),['firstname','lastname','email','phone','password','current_password'])) reply(422,['error'=>'Unsupported account fields']);
        $db=database(); $fields=[];
        foreach (['firstname'=>100,'lastname'=>100,'email'=>150,'phone'=>30] as $key=>$limit) if (array_key_exists($key,$body)) $fields[$key]=textField($body,$key,$limit,$key!=='phone');
        if (isset($fields['email']) && !filter_var($fields['email'],FILTER_VALIDATE_EMAIL)) reply(422,['error'=>'Valid email required']);
        if (array_key_exists('password',$body) || isset($fields['email'])) {
            $q=$db->prepare('SELECT password_hash FROM users WHERE id=?'); $q->execute([$user['id']]);
            if (!is_string($body['current_password']??null) || !password_verify($body['current_password'],$q->fetchColumn())) reply(403,['error'=>'Current password required to change email or password']);
        }
        if (array_key_exists('password',$body)) $fields['password_hash']=password_hash(validPassword($body['password']),PASSWORD_DEFAULT);
        if (!$fields) reply(422,['error'=>'No account changes supplied']);
        $db->beginTransaction();
        $q=$db->prepare('UPDATE users SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($fields))).' WHERE id=?'); $q->execute([...array_values($fields),$user['id']]);
        profileFor($db,currentUser()); $db->commit();
        reply(200,['user'=>currentUser()]);
    }
    methods(['POST']); $body=jsonBody(8192);
    $email=textField($body,'email',150);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) reply(422,['error'=>'Valid email required']);
    $db=database();
    if ($path==='/api/auth/register') {
        if (array_diff(array_keys($body),['firstname','lastname','email','phone','password'])) reply(422,['error'=>'Unsupported registration fields']);
        $first=textField($body,'firstname',100); $last=textField($body,'lastname',100); $phone=textField($body,'phone',30,false);
        $password=validPassword($body['password']??null);
        $q=$db->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$email]);
        if ($q->fetch()) reply(409,['error'=>'An account with this email already exists']);
        $db->beginTransaction();
        $q=$db->prepare("INSERT INTO users (firstname,lastname,email,phone,password_hash,role) VALUES (?,?,?,?,?,'user')"); $q->execute([$first,$last,$email,$phone,password_hash($password,PASSWORD_DEFAULT)]);
        $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$db->lastInsertId()]); $user=$q->fetch();
        $person=profileFor($db,$user); $db->commit(); signIn($db,$user);
        reply(201,['user'=>currentUser(),'csrf_token'=>$_SESSION['csrf_token'],'redirect'=>'/']);
    }
    if ($path==='/api/auth/login') {
        if (!is_string($body['password']??null) || $body['password']==='' || strlen($body['password'])>1024) reply(422,['error'=>'Password required']);
        if (!consumeLoginAttempt($_SERVER['REMOTE_ADDR']??'unknown', $email)) { header('Retry-After: 60'); reply(429,['error'=>'Too many sign-in attempts. Try again in a minute.']); }
        $q=$db->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $user=$q->fetch();
        if (!$user || !password_verify($body['password'],$user['password_hash'])) reply(401,['error'=>'Invalid email or password']);
        if (password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT)) { $q=$db->prepare('UPDATE users SET password_hash=? WHERE id=?'); $q->execute([password_hash($body['password'],PASSWORD_DEFAULT),$user['id']]); }
        signIn($db,$user); reply(200,['user'=>currentUser(),'csrf_token'=>$_SESSION['csrf_token'],'redirect'=>$user['role']==='admin'?'/admin.php':'/']);
    }
    reply(404,['error'=>'Route not found']);
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); dbFailure($e); }

function validPassword(mixed $value): string {
    if (!is_string($value) || strlen($value)<12 || strlen($value)>72) reply(422,['error'=>'Password must contain 12 to 72 bytes']);
    return $value;
}
