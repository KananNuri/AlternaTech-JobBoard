<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/integration/common.php';
try { $user=currentUser(false); }
catch (Throwable) { http_response_code(503); echo 'Administration temporarily unavailable.'; exit; }
if (!$user) { header('Location: /login.php',true,303); exit; }
if ($user['role']!=='admin') { http_response_code(403); echo 'Administrator access required.'; exit; }
require __DIR__.'/page.php'; pageStart('page.admin');
?>
<section class="app-panel admin-panel"><?= i18nText('admin.table', [], 'label', ' for="resource"') ?> <select id="resource"></select><div class="actions"><?= i18nText('admin.create', [], 'button', ' id="new-record" disabled') ?><?= i18nText('account.signOut', [], 'button', ' id="admin-logout" class="secondary" hidden') ?></div><p id="admin-notice" class="notice" role="status"></p><section id="record-editor" hidden><?= i18nText('admin.record', [], 'h2', ' id="editor-title"') ?><form id="record-form" class="form-grid"></form></section><div class="table-wrap"><table><thead id="records-head"></thead><tbody id="records-body"></tbody></table></div><div class="pagination"><?= i18nText('pager.prev', [], 'button', ' id="admin-prev" disabled') ?><span id="admin-page"></span><?= i18nText('pager.next', [], 'button', ' id="admin-next" disabled') ?></div></section>
<script src="/assets/admin.js" defer></script>
<?php pageEnd(); ?>
