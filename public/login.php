<?php require __DIR__.'/page.php'; pageStart('Sign in'); ?>
<section class="app-panel"><form id="signin" class="form-grid"><label>Email<input name="email" type="email" autocomplete="username" maxlength="150" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button>Sign in</button><p id="account-notice" role="status" class="notice"></p></form><p><a href="/account.php">Create an account</a></p><section id="google-account" aria-live="polite"></section></section>
<script src="/assets/account.js" defer></script><script src="/assets/google-login.js" defer></script>
<?php pageEnd(); ?>
