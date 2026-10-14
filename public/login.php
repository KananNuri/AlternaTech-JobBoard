<?php require __DIR__.'/page.php'; pageStart('page.signIn'); ?>
<section class="app-panel"><form id="signin" class="form-grid"><label><?= i18nText('field.email') ?><input name="email" type="email" autocomplete="username" maxlength="150" required></label><label><?= i18nText('field.password') ?><input name="password" type="password" autocomplete="current-password" required></label><?= i18nText('login.submit', [], 'button') ?><p id="account-notice" role="status" class="notice"></p></form><p><?= i18nText('login.createAccount', [], 'a', ' href="/account.php"') ?></p><section id="google-account" aria-live="polite"></section></section>
<script src="/assets/account.js" defer></script><script src="/assets/google-login.js" defer></script>
<?php pageEnd(); ?>
