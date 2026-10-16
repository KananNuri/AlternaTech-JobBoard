<?php
declare(strict_types=1);
require __DIR__.'/page.php';
try {$viewer=currentUser(false);} catch(Throwable) {http_response_code(503);exit('Service temporarily unavailable');}
if (!$viewer) {header('Location: /login.php',true,303);exit;}
pageStart('access.title');
?>
<p class="muted"><?= i18nText('access.intro') ?></p>
<p id="access-notice" class="notice" role="status" aria-live="polite"></p>
<div class="access-grid">
<section class="app-panel"><h2><?= i18nText('access.create') ?></h2><p><?= i18nText('access.createHelp') ?></p>
<form id="company-proposal" class="form-grid">
<label><?= i18nText('access.name') ?><input name="name" maxlength="150" required></label>
<label><?= i18nText('access.email') ?><input name="email" type="email" maxlength="150" required></label>
<label><?= i18nText('access.website') ?><input name="website" type="url" maxlength="255"></label>
<label><?= i18nText('access.registration') ?><input name="registration_number" maxlength="50"></label>
<label><?= i18nText('access.evidence') ?><textarea name="evidence" maxlength="2000" required></textarea></label>
<?= i18nText('access.submit',[],'button') ?><p class="notice" role="status"></p>
</form></section>
<section class="app-panel"><h2><?= i18nText('access.join') ?></h2><p><?= i18nText('access.joinHelp') ?></p>
<form id="company-search" class="form-grid"><label><?= i18nText('access.search') ?><input name="q" minlength="2" maxlength="150" required></label><?= i18nText('filters.search',[],'button') ?></form>
<p id="search-notice" class="notice" role="status"></p><div id="company-results" class="access-list"></div>
<form id="company-join" class="form-grid" hidden><h3 id="selected-company"></h3><label><?= i18nText('access.message') ?><textarea name="message" maxlength="2000" required></textarea></label><?= i18nText('access.requestJoin',[],'button') ?><p class="notice" role="status"></p></form>
<?php if($viewer['role']==='admin'): ?>
<form id="company-manager" class="form-grid" hidden><h3><?= i18nText('access.assign') ?></h3><p><?= i18nText('access.verify') ?></p><p><?= i18nText('access.transferHelp') ?></p><label><?= i18nText('access.accountEmail') ?><input name="email" type="email" maxlength="150" required></label><?= i18nText('access.assign',[],'button') ?><p class="notice" role="status"></p></form>
<?php endif; ?>
</section></div>
<section class="app-panel"><h2><?= i18nText('access.myStatus') ?></h2><p><?= i18nText('access.limit') ?></p><div id="company-state" class="access-list"></div></section>
<section class="app-panel"><h2><?= i18nText('access.inbox') ?></h2><div id="join-inbox" class="access-list"></div></section>
<section class="app-panel"><h2><?= i18nText('access.members') ?></h2><p><?= i18nText('access.authority') ?></p><div id="company-members" class="access-list"></div></section>
<?php if($viewer['role']==='admin'): ?>
<section class="app-panel"><h2><?= i18nText('access.proposals') ?></h2><p><?= i18nText('access.verify') ?></p><div id="proposal-inbox" class="access-list"></div></section>
<?php endif; ?>
<script src="/assets/company-access.js" defer></script>
<?php pageEnd(); ?>
