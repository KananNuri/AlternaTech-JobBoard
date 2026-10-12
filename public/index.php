<?php
declare(strict_types=1);
// Extend the existing Charles/Kanan page without changing Charles's HTML or styles.
$page=file_get_contents(__DIR__.'/index.html');
$page=str_replace('</head>','<link rel="stylesheet" href="/assets/integration.css"><script src="/assets/integration.js" defer></script></head>',$page);
$page=str_replace('</header>','</header><nav class="app-nav" aria-label="Main navigation"><a href="/">Job board</a><a href="/login.php">Sign in</a><a href="/account.php">Create or edit account</a><a href="/admin.php" id="admin-link" hidden>Administration</a></nav>',$page);
echo $page;
