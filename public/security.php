<?php require __DIR__.'/page.php'; pageStart('Account security'); ?>
<section class="app-panel">
  <form id="security-form" class="form-grid">
    <label id="security-email">Email<input name="email" type="email" autocomplete="email" maxlength="150" required></label>
    <label id="security-password" hidden>New password<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72"></label>
    <button id="security-submit">Send password reset link</button>
    <p id="security-notice" role="status" class="notice"></p>
  </form>
  <p><a href="/login.php">Return to sign in</a></p>
</section>
<script src="/assets/security.js" defer></script>
<?php pageEnd(); ?>
