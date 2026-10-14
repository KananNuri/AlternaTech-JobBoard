<?php
declare(strict_types=1);
// Canonical integration entry point, also used by the legacy router.
require_once __DIR__.'/integration/common.php';
if (!defined('ALTERNATECH_INTEGRATED_ROUTER')) define('ALTERNATECH_INTEGRATED_ROUTER',true);
ini_set('display_errors','0');
$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'','/');
try {
    if ($path==='/api/auth/csrf') {
        methods(['GET']); startSession();
        $_SESSION['csrf_token']??=bin2hex(random_bytes(32)); reply(200,['csrf_token'=>$_SESSION['csrf_token']]);
    }
    if (in_array($path,['/api/auth/login','/api/auth/session','/api/auth/logout','/api/auth/register','/api/account'],true)) { require __DIR__.'/integration/auth.php'; return true; }
    if (preg_match('~^/api/admin(?:/|$)~',$path)) { require __DIR__.'/integration/admin.php'; return true; }
    if ($path==='/api/recruiter/applications') { require __DIR__.'/integration/recruiter.php'; return true; }
    if ($path==='/api/catalog/ads' || $path==='/api/categories' || preg_match('~^/api/companies/([1-9][0-9]*)/ads$~',$path,$matches)) {
        if (!empty($matches[1])) $GLOBALS['route_company_id']=$matches[1];
        require __DIR__.'/integration/catalog.php'; return true;
    }
    if (preg_match('~^/api/ads/([1-9][0-9]*)/applications$~',$path,$matches) && $_SERVER['REQUEST_METHOD']==='POST') {
        $GLOBALS['route_ad_id']=$matches[1]; require __DIR__.'/integration/apply.php'; return true;
    }
    if ($path==='/api/applications/mine' && $_SERVER['REQUEST_METHOD']==='POST') {
        currentUser(); $body=jsonBody();
        $GLOBALS['route_ad_id']=positiveId($body['advertisement_id']??null);
        $GLOBALS['application_body']=$body; require __DIR__.'/integration/apply.php'; return true;
    }
    // Canonical auth is required even for legacy alias routes.
    if (preg_match('~^/api/ads/[1-9][0-9]*/applications$~',$path)) {
        methods(['POST']);
    }
    // Revalidate canonical accounts/expiry before entering Charles's protected routes.
    if (str_starts_with($path,'/api/companies/mine') || $path==='/api/applications/mine' || (preg_match('~^/api/ads(?:/|$)~',$path) && $_SERVER['REQUEST_METHOD']!=='GET')) {
        currentUser(false); session_write_close();
    }
    return require __DIR__.'/router.php';
} catch (Throwable $e) { dbFailure($e); }
