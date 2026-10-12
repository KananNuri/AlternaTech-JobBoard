<?php
declare(strict_types=1);
function pageStart(string $title): void {
    $safe=htmlspecialchars($title,ENT_QUOTES,'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$safe.' · AlternaTech</title><link rel="stylesheet" href="/assets/jobboard.css"><link rel="stylesheet" href="/assets/integration.css"></head><body><header><a class="brand" href="/">AlternaTech<span>JOB BOARD</span></a><span>IT · Data · AI · Cloud</span></header><nav class="app-nav" aria-label="Main navigation"><a href="/">Job board</a><a href="/login.php">Sign in</a><a href="/account.php">My account</a></nav><main><h1>'.$safe.'</h1>';
}
function pageEnd(): void { echo '</main><footer>AlternaTech · Start small. Build something great.</footer></body></html>'; }
