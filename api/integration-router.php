<?php
declare(strict_types=1);
// Additive integration entry point. Charles's router and handlers stay byte-for-byte intact.
require_once __DIR__.'/integration/common.php';
ini_set('display_errors','0');
// parse_url() returns false for some paths (e.g. a raw ':' as in "1:3"); every handler parses the URI the same way,
// so refuse such requests cleanly instead of crashing. Clients encode ':' as %3A (admin.js already does).
$parsedPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($parsedPath===false) reply(400,['error'=>'Invalid request path']);
$path=rtrim($parsedPath??'','/');
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
    // Contract: POST only (guest or signed-in apply). Charles's GET handler for this path ignored the advertisement ID.
    if (preg_match('~^/api/ads/([1-9][0-9]*)/applications$~',$path,$matches)) {
        methods(['POST']); $GLOBALS['route_ad_id']=$matches[1]; require __DIR__.'/integration/apply.php'; return true;
    }
    // Revalidate canonical accounts/expiry before entering Charles's protected routes.
    if (str_starts_with($path,'/api/companies/mine') || $path==='/api/applications/mine' || (preg_match('~^/api/ads(?:/|$)~',$path) && $_SERVER['REQUEST_METHOD']!=='GET')) {
        currentUser(false); session_write_close();
    }
    return require __DIR__.'/router.php';
} catch (Throwable $e) { dbFailure($e); }
