<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/config/database.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $db=database();
    $exists=fn(string $table,string $column)=> (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='$column'")->fetchColumn();
    $index=fn(string $table,string $name)=> (bool)$db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='$table' AND index_name='$name'")->fetchColumn();
    foreach (['people'=>['user_id'],'applications'=>['person_id','advertisement_id']] as $table=>$fields) {
        $where=$table==='people'?' WHERE user_id IS NOT NULL':'';
        if ($db->query('SELECT 1 FROM '.$table.$where.' GROUP BY '.implode(',',$fields).' HAVING COUNT(*)>1 LIMIT 1')->fetchColumn()) throw new RuntimeException('Duplicate records need review before migration');
    }
    if (!$index('people','unique_person_account')) $db->exec('ALTER TABLE people ADD UNIQUE KEY unique_person_account (user_id)');
    if (!$index('applications','unique_person_ad')) $db->exec('ALTER TABLE applications ADD UNIQUE KEY unique_person_ad (person_id,advertisement_id)');
    // Compatibility date lets Charles keep his existing application queries unchanged.
    if (!$exists('applications','application_date')) $db->exec('ALTER TABLE applications ADD application_date TIMESTAMP GENERATED ALWAYS AS (created_at) VIRTUAL');
    if (!$exists('advertisements','created_by')) $db->exec('ALTER TABLE advertisements ADD created_by INT UNSIGNED NULL, ADD CONSTRAINT fk_ad_creator FOREIGN KEY (created_by) REFERENCES people(id) ON DELETE SET NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS company_members (company_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NOT NULL, role ENUM('recruiter') NOT NULL DEFAULT 'recruiter', PRIMARY KEY(company_id,person_id), CONSTRAINT fk_member_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE, CONSTRAINT fk_member_person FOREIGN KEY(person_id) REFERENCES people(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS google_identities (google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id INT UNSIGNED NOT NULL UNIQUE, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_google_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    echo "PASS integration migration (safe to rerun; no records removed)\n";
} catch (Throwable $e) { fwrite(STDERR,'Migration failed: '.$e->getMessage()."\n"); exit(1); }
