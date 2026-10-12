<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/integration/common.php';
try { $user=currentUser(false); }
catch (Throwable) { http_response_code(503); echo 'Administration temporarily unavailable.'; exit; }
if (!$user) { header('Location: /login.php',true,303); exit; }
if ($user['role']!=='admin') { http_response_code(403); echo 'Administrator access required.'; exit; }
require __DIR__.'/page.php'; pageStart('Administration');
?>
<section class="app-panel"><label for="resource">Database table</label> <select id="resource"></select><div class="actions"><button id="new-record">Create record</button><button id="admin-logout" class="secondary">Sign out</button></div><p id="admin-notice" class="notice" role="status"></p><section id="record-editor" hidden><h2 id="editor-title">Record</h2><form id="record-form" class="form-grid"></form></section><div class="table-wrap"><table><thead id="records-head"></thead><tbody id="records-body"></tbody></table></div><div class="pagination"><button id="admin-prev">Previous</button><span id="admin-page"></span><button id="admin-next">Next</button></div></section>
<script src="/assets/admin.js" defer></script>
<?php pageEnd(); ?>
