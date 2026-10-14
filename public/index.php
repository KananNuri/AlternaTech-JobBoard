<?php
declare(strict_types=1);
// Board page: index.html plus the integration layer, the session-aware header and server-side translation.
require __DIR__.'/page.php';
$user=navUser();
$page=file_get_contents(__DIR__.'/index.html');
$page=str_replace('<head>','<head>'.i18nBootstrap(),$page);
$page=str_replace('</head>','<link rel="stylesheet" href="/assets/integration.css"><script src="/assets/site.js" defer></script><script src="/assets/integration.js" defer></script></head>',$page);
$page=str_replace('<div class="account-menu">',siteNav($user).langSwitch().'<div class="account-menu">',$page);
echo translateHtml($page, function (Dom\HTMLDocument $document) use ($user): void {
    if (!$user) return;
    // Initial display state only; login.js and integration.js keep it in sync after an in-page sign-in.
    $toggle = $document->getElementById('account-toggle');
    $toggle->removeAttribute('data-i18n'); $toggle->textContent = $user['firstname'].' '.$user['lastname'];
    $document->getElementById('my-space')->removeAttribute('hidden');
});
