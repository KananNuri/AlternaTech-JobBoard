<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/integration/common.php';
require_once __DIR__.'/i18n.php';
// Signed-in account for the header only: display state, never an access control (pages and APIs check on their own).
// Visitors without a session cookie never get a session started for them.
function navUser(): ?array {
    if (empty($_COOKIE['alternatech_login'])) return null;
    try { $user = currentUser(false); } catch (Throwable) { $user = null; }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    return $user;
}
// Every link is rendered; data-auth lets the board update the header after an in-page sign-in.
function siteNav(?array $user): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/index.php') $path = '/';
    $links = [['/', 'nav.board', 'all', ''], ['/account.php', 'nav.createAccount', 'guest', ''], ['/#my-space', 'nav.mySpace', 'user', ''], ['/account.php', 'nav.myAccount', 'user', ''], ['/admin.php', 'nav.admin', 'admin', 'admin-link']];
    $html = '<nav class="app-nav"'.i18nAttr('aria-label', 'nav.label').'>';
    foreach ($links as [$href, $key, $auth, $id]) {
        $visible = $auth === 'all' || ($auth === 'guest' && !$user) || ($auth === 'user' && $user) || ($auth === 'admin' && ($user['role'] ?? '') === 'admin');
        $html .= i18nText($key, [], 'a', ' href="'.$href.'"'.($id ? ' id="'.$id.'"' : '').($auth !== 'all' ? ' data-auth="'.$auth.'"' : '').($visible ? '' : ' hidden').($visible && $path === $href ? ' aria-current="page"' : ''));
    }
    return $html.'</nav>';
}
function displayName(array $user): string { return esc($user['firstname'].' '.$user['lastname']); }
// Account menu of the PHP pages; the board renders its own around login.js's sign-in form.
function accountMenu(?array $user): string {
    if (!$user) {
        $current = (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/login.php') ? ' aria-current="page"' : '';
        return '<div class="account-menu">'.i18nText('account.signIn', [], 'a', ' class="account-toggle" href="/login.php"'.$current).'</div>';
    }
    return '<div class="account-menu"><button type="button" id="account-toggle" class="account-toggle" aria-expanded="false" aria-controls="account-panel">'.displayName($user).'</button>'
        .'<div id="account-panel" class="account-panel" hidden>'.i18nText('account.signedInAs', ['name' => $user['firstname'].' '.$user['lastname']], 'p', ' class="account-name"').'<p class="muted">'.esc($user['email']).'</p>'
        .i18nText('account.signOut', [], 'button', ' type="button" id="site-logout"').'<p id="site-logout-status" class="notice" role="status"></p></div></div>';
}
function siteScripts(): string { return i18nBootstrap().'<script src="/assets/i18n.js" defer></script><script src="/assets/site.js" defer></script>'; }
function pageStart(string $titleKey): void {
    $user=navUser();
    echo '<!doctype html><html lang="'.currentLang().'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .i18nText('title.page', ['page' => ['k' => $titleKey]], 'title')
        .'<link rel="stylesheet" href="/assets/jobboard.css"><link rel="stylesheet" href="/assets/integration.css">'.siteScripts().'</head>'
        .'<body'.(in_array($titleKey,['page.signIn','page.account'],true)?' class="auth-page"':'').'><header><a class="brand" href="/">AlternaTech<span>JOB BOARD</span></a>'.i18nText('site.tagline').siteNav($user).langSwitch().accountMenu($user).'</header><main>'.i18nText($titleKey, [], 'h1');
}
function pageEnd(): void { echo '</main>'.i18nText('site.footer', [], 'footer').'</body></html>'; }
