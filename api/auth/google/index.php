<?php
declare(strict_types=1);
require __DIR__.'/functions.php';
require_once dirname(__DIR__,2).'/integration/common.php';
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
function googleReply(int $status,array $data): never {http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$config=googleConfig();
$secure=str_starts_with($config['redirect'],'https://') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
startSession();
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
$routes=['/api/auth/google/start'=>'GET','/api/auth/google/callback'=>'GET','/api/auth/google/session'=>'GET','/api/auth/google/logout'=>'POST'];
if (!isset($routes[$path])) googleReply(404,['error'=>'Route not found']);
if ($method!==$routes[$path]) {header('Allow: '.$routes[$path]);googleReply(405,['error'=>'Method not allowed']);}
if ($path==='/api/auth/google/session') {
    try {
        $user=currentUser(false);
        googleReply(200,['enabled'=>$config['enabled'],'user'=>$user,'csrf_token'=>$user?$_SESSION['csrf_token']:null]);
    } catch(Throwable $e) {error_log('Google session failure: '.get_class($e));googleReply(503,['error'=>'Account temporarily unavailable']);}
}
if ($path==='/api/auth/google/logout') {
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrf) || !isset($_SESSION['csrf_token'],$_SESSION['account_id']) || !hash_equals($_SESSION['csrf_token'],$csrf)) googleReply(403,['error'=>'Invalid CSRF token']);
    $_SESSION=[];session_destroy();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);googleReply(200,['message'=>'Signed out']);
}
if (!$config['enabled']) googleReply(503,['error'=>'Google login is not configured']);
if ($path==='/api/auth/google/start') {
    $pending=['state'=>bin2hex(random_bytes(32)),'nonce'=>bin2hex(random_bytes(32)),'verifier'=>bin2hex(random_bytes(32)),'created'=>time()];$_SESSION['google_pending']=$pending;
    $url='https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$config['client'],'redirect_uri'=>$config['redirect'],'response_type'=>'code','scope'=>'openid email profile','state'=>$pending['state'],'nonce'=>$pending['nonce'],'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$pending['verifier'],true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account']);
    header('Location: '.$url,true,302);exit;
}
$pending=$_SESSION['google_pending'] ?? [];unset($_SESSION['google_pending']);
if (!googleState($pending,$_GET['state'] ?? null,time())) googleReply(400,['error'=>'Invalid or expired Google login request']);
if (isset($_GET['error'])) {header('Location: /?google_login=cancelled',true,303);exit;}
$code=$_GET['code'] ?? null;
if (!is_string($code) || $code==='' || strlen($code)>4096) googleReply(400,['error'=>'Invalid authorization code']);
try {
    $tokens=googleHttp('https://oauth2.googleapis.com/token',['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$config['client'],'client_secret'=>$config['secret'],'redirect_uri'=>$config['redirect'],'code_verifier'=>$pending['verifier']]);
    $claims=googleClaimsFromExchange($tokens['id_token'] ?? '',$config['client'],$pending['nonce'],time());
    $user=googleUser(database(),$claims);
    signIn(database(),$user);
    header('Location: '.($user['role']==='admin'?'/admin.php':'/?google_login=success'),true,303);exit;
} catch(GoogleAccountConflict) {googleReply(409,['error'=>'An account with this email already exists. Use your existing login; account linking requires verification.']);}
catch(Throwable $e) {error_log('Google login failure: '.get_class($e));googleReply(503,['error'=>'Google login could not be completed. Please try again.']);}
