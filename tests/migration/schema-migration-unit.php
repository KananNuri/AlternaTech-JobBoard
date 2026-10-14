<?php
declare(strict_types=1);
// Pure tests of tools/migration-lib.php: no database, no network. php tests/migration/schema-migration-unit.php
require dirname(__DIR__, 2).'/tools/migration-lib.php';

$checks = 0; $failures = [];
function check(bool $ok, string $what): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $what; }
function ids(array $plan): array { return array_column($plan['steps'], 'id'); }

// ------------------------------------------------------------------ synthetic snapshots
const LEGACY_COLLATION = 'utf8mb4_0900_ai_ci';
function c(string $type, bool $null = false, ?string $default = null, string $extra = '', string $gen = ''): array {
    $dt = strtolower(strtok($type, ' ('));
    return ['type' => $type, 'data_type' => $dt, 'nullable' => $null, 'default' => $default, 'collation' => in_array($dt, ['varchar', 'char', 'text', 'enum'], true) ? LEGACY_COLLATION : null, 'extra' => $extra, 'generation' => $gen];
}
function ix(bool $unique, string ...$cols): array { return ['unique' => $unique, 'columns' => $cols]; }
function fk(string $col, string $refTable, string $rule = 'NO ACTION'): array { return ['columns' => [$col], 'ref_table' => $refTable, 'ref_columns' => ['id'], 'on_delete' => $rule]; }
function t(array $columns, array $indexes, array $fks = []): array { return ['collation' => LEGACY_COLLATION, 'auto_increment' => 52, 'columns' => $columns, 'indexes' => $indexes, 'fks' => $fks, 'checks' => []]; }
$ai = 'auto_increment';

/** The historical database: Charles's structure (people with credentials, no users), as found in the backup's DDL. */
function historical(): array {
    $id = c('int', false, null, 'auto_increment');
    return ['database' => 'unit', 'tables' => [
        'people' => t(['id' => $id, 'first_name' => c('varchar(100)'), 'last_name' => c('varchar(100)'), 'email' => c('varchar(255)'), 'phone' => c('varchar(30)', true), 'password' => c('varchar(255)'), 'role' => c('varchar(20)', false, 'user')], ['PRIMARY' => ix(true, 'id'), 'email' => ix(true, 'email')]),
        'companies' => t(['id' => $id, 'name' => c('varchar(255)')], ['PRIMARY' => ix(true, 'id'), 'name' => ix(true, 'name')]),
        'categories' => t(['id' => $id, 'name' => c('varchar(100)')], ['PRIMARY' => ix(true, 'id'), 'name' => ix(true, 'name')]),
        'advertisements' => t(['id' => $id, 'title' => c('varchar(255)'), 'short_description' => c('varchar(500)'), 'description' => c('text'), 'salary' => c('varchar(100)', true), 'location' => c('varchar(255)'), 'working_time' => c('varchar(100)'), 'company_id' => c('int'), 'category_id' => c('int'), 'created_by' => c('int')],
            ['PRIMARY' => ix(true, 'id'), 'company_id' => ix(false, 'company_id'), 'category_id' => ix(false, 'category_id'), 'created_by' => ix(false, 'created_by')],
            ['advertisements_ibfk_1' => fk('company_id', 'companies'), 'advertisements_ibfk_2' => fk('category_id', 'categories'), 'advertisements_ibfk_3' => fk('created_by', 'people')]),
        'applications' => t(['id' => $id, 'person_id' => c('int'), 'advertisement_id' => c('int'), 'message' => c('text', true), 'application_date' => c('datetime', false, 'CURRENT_TIMESTAMP', 'default_generated'), 'status' => c('varchar(50)', false, 'pending')],
            ['PRIMARY' => ix(true, 'id'), 'uq_applications_person_ad' => ix(true, 'person_id', 'advertisement_id'), 'advertisement_id' => ix(false, 'advertisement_id')],
            ['applications_ibfk_1' => fk('person_id', 'people'), 'applications_ibfk_2' => fk('advertisement_id', 'advertisements')]),
        'company_members' => t(['id' => $id, 'person_id' => c('int'), 'company_id' => c('int'), 'role' => c('varchar(50)')],
            ['PRIMARY' => ix(true, 'id'), 'person_id' => ix(false, 'person_id'), 'company_id' => ix(false, 'company_id')],
            ['company_members_ibfk_1' => fk('person_id', 'people'), 'company_members_ibfk_2' => fk('company_id', 'companies')]),
    ]];
}
/** Kanan's schema (users/people firstname, no company_members, created_at only on applications). */
function kanan(): array {
    $id = c('int unsigned', false, null, 'auto_increment'); $u = fn(bool $n = false) => c('int unsigned', $n); $ts = c('timestamp', true, 'CURRENT_TIMESTAMP', 'default_generated');
    return ['database' => 'unit', 'tables' => [
        'companies' => t(['id' => $id, 'name' => c('varchar(150)'), 'description' => c('text', true), 'email' => c('varchar(150)', true), 'phone' => c('varchar(30)', true), 'website' => c('varchar(255)', true), 'created_at' => $ts], ['PRIMARY' => ix(true, 'id')]),
        'categories' => t(['id' => $id, 'name' => c('varchar(100)')], ['PRIMARY' => ix(true, 'id'), 'name' => ix(true, 'name')]),
        'users' => t(['id' => $id, 'firstname' => c('varchar(100)'), 'lastname' => c('varchar(100)'), 'email' => c('varchar(150)'), 'phone' => c('varchar(30)', true), 'password_hash' => c('varchar(255)'), 'role' => c("enum('user','admin')", false, 'user'), 'created_at' => $ts, 'updated_at' => $ts], ['PRIMARY' => ix(true, 'id'), 'email' => ix(true, 'email')]),
        'people' => t(['id' => $id, 'user_id' => $u(true), 'firstname' => c('varchar(100)'), 'lastname' => c('varchar(100)'), 'email' => c('varchar(150)'), 'phone' => c('varchar(30)', true), 'created_at' => $ts], ['PRIMARY' => ix(true, 'id'), 'fk_people_user' => ix(false, 'user_id')], ['fk_people_user' => fk('user_id', 'users', 'SET NULL')]),
        'advertisements' => t(['id' => $id, 'company_id' => $u(), 'category_id' => $u(true), 'title' => c('varchar(200)'), 'short_description' => c('varchar(500)'), 'description' => c('text'), 'salary' => c('varchar(100)', true), 'location' => c('varchar(150)'), 'working_time' => c('varchar(100)', true), 'contract_type' => c('varchar(100)', true), 'source' => c("enum('LOCAL','FRANCE_TRAVAIL')", false, 'LOCAL'), 'external_id' => c('varchar(100)', true), 'source_url' => c('varchar(500)', true), 'created_at' => $ts, 'updated_at' => $ts],
            ['PRIMARY' => ix(true, 'id'), 'unique_external_job' => ix(true, 'source', 'external_id'), 'fk_advertisement_company' => ix(false, 'company_id'), 'fk_advertisement_category' => ix(false, 'category_id')],
            ['fk_advertisement_company' => fk('company_id', 'companies', 'CASCADE'), 'fk_advertisement_category' => fk('category_id', 'categories', 'SET NULL')]),
        'applications' => t(['id' => $id, 'advertisement_id' => $u(), 'person_id' => $u(), 'message' => c('text', true), 'status' => c("enum('pending','reviewed','accepted','rejected')", false, 'pending'), 'created_at' => $ts],
            ['PRIMARY' => ix(true, 'id'), 'fk_application_advertisement' => ix(false, 'advertisement_id'), 'fk_application_person' => ix(false, 'person_id')],
            ['fk_application_advertisement' => fk('advertisement_id', 'advertisements', 'CASCADE'), 'fk_application_person' => fk('person_id', 'people', 'CASCADE')]),
    ]];
}
/** main before this work: Kanan + created_by, application_date alias, unique pairs, company_members, google_identities. */
function mainShape(): array {
    $s = kanan(); $u = fn(bool $n = false) => c('int unsigned', $n);
    $s['tables']['people']['indexes']['unique_person_account'] = ix(true, 'user_id'); unset($s['tables']['people']['indexes']['fk_people_user']);
    $s['tables']['advertisements']['columns']['created_by'] = $u(true);
    $s['tables']['advertisements']['indexes']['fk_ad_creator'] = ix(false, 'created_by');
    $s['tables']['advertisements']['fks']['fk_ad_creator'] = fk('created_by', 'people', 'SET NULL');
    $s['tables']['applications']['columns']['application_date'] = c('timestamp', true, null, 'virtual generated', '`created_at`');
    $s['tables']['applications']['indexes']['unique_person_ad'] = ix(true, 'person_id', 'advertisement_id');
    $s['tables']['company_members'] = t(['company_id' => $u(), 'person_id' => $u(), 'role' => c("enum('recruiter')", false, 'recruiter')], ['PRIMARY' => ix(true, 'company_id', 'person_id'), 'fk_member_person' => ix(false, 'person_id')], ['fk_member_company' => fk('company_id', 'companies', 'CASCADE'), 'fk_member_person' => fk('person_id', 'people', 'CASCADE')]);
    $s['tables']['google_identities'] = t(['google_sub' => c('varchar(255)'), 'user_id' => $u(), 'created_at' => c('timestamp', false, 'CURRENT_TIMESTAMP', 'default_generated')], ['PRIMARY' => ix(true, 'google_sub'), 'user_id' => ix(true, 'user_id')], ['fk_google_identity_user' => fk('user_id', 'users', 'CASCADE')]);
    return $s;
}
/** What database/schema.sql creates (canonical-v1). */
function freshCanonical(): array {
    $s = mainShape();
    $s['tables']['companies']['indexes']['idx_companies_name'] = ix(false, 'name');
    $s['tables']['people']['indexes']['idx_people_email'] = ix(false, 'email');
    $s['tables']['advertisements']['fks']['fk_advertisement_company']['on_delete'] = 'RESTRICT';
    $s['tables']['applications']['columns']['application_date'] = c('datetime', true, 'CURRENT_TIMESTAMP', 'default_generated');
    $s['tables']['applications']['columns']['created_at'] = c('datetime', true, null, 'virtual generated', '`application_date`');
    $s['tables']['applications']['checks']['chk_application_date'] = ['clause' => '(`application_date` is not null)', 'enforced' => true];
    $s['tables']['schema_migrations'] = t(['name' => c('varchar(100)'), 'kind' => c('varchar(10)'), 'applied_at' => c('datetime')], ['PRIMARY' => ix(true, 'name')]);
    return $s;
}
$accounts = ['account_candidates' => 4, 'bcrypt' => 3, 'locked_ids' => [4], 'blockers' => []];

// ------------------------------------------------------------------ shapes and setup decisions
check(detectShape(['database' => 'x', 'tables' => []])['shape'] === 'empty', 'empty database detected');
check(detectShape(historical())['shape'] === 'historical', 'historical shape detected');
check(detectShape(kanan())['shape'] === 'upgradable', 'Kanan shape is upgradable');
check(detectShape(mainShape())['shape'] === 'upgradable', 'previous main shape is upgradable');
check(detectShape(freshCanonical())['shape'] === 'canonical' && verifyCanonical(freshCanonical()) === [], 'schema.sql shape is canonical');
check(setupDecision(['database' => 'x', 'tables' => []])['action'] === 'install', 'setup installs an empty database');
check(setupDecision(freshCanonical())['action'] === 'canonical', 'setup accepts a canonical database without changes');
foreach (['historical' => historical(), 'kanan' => kanan(), 'main' => mainShape()] as $n => $s) check(setupDecision($s)['action'] === 'refuse', "setup refuses the $n shape instead of altering it");
check(planMigration(['database' => 'x', 'tables' => []])['steps'] === [] && planMigration(['database' => 'x', 'tables' => []])['blockers'] !== [], 'migrate refuses an empty database (setup installs it)');

// ------------------------------------------------------------------ historical plan
$plan = planMigration(historical(), $accounts);
$expected = ['create-schema-migrations', 'create-users', 'people-rename-names', 'people-legacy-nullable', 'people-user-id', 'people-created-at', 'migrate-accounts', 'people-email-index', 'companies-columns', 'companies-name-index', 'advertisements-columns', 'advertisements-working-time-nullable', 'advertisements-external-unique', 'applications-date-not-null-check', 'applications-created-at-alias', 'applications-status-enum', 'company-members-unique', 'create-google-identities', 'fk-people-user_id', 'fk-advertisements-category_id', 'fk-advertisements-created_by', 'fk-applications-advertisement_id', 'fk-applications-person_id', 'fk-company_members-company_id', 'fk-company_members-person_id', 'fk-google_identities-user_id'];
check($plan['blockers'] === [], 'historical plan has no blocker');
check(ids($plan) === $expected, 'historical plan: exact steps and order (' . implode(',', array_diff($expected, ids($plan))) . '|' . implode(',', array_diff(ids($plan), $expected)) . ')');
check(!in_array('fk-advertisements-company_id', ids($plan), true), 'company -> advertisements stays RESTRICT (historical NO ACTION kept)');
check(verifyCanonical($plan['projected']) === [], 'historical plan reaches the canonical contract: ' . implode('; ', verifyCanonical($plan['projected'])));
check(detectShape($plan['projected'])['shape'] === 'canonical', 'projected historical database is detected as canonical');
check(planMigration($plan['projected'], ['account_candidates' => 0])['steps'] === [], 'idempotent: nothing left to do on the projected result');
check(!in_array('migrate-accounts', ids(planMigration(historical(), ['account_candidates' => 0])), true), 'no data step when no profile holds a legacy password');
$sql = implode("\n", array_merge(...array_column($plan['steps'], 'sql')));
check(!preg_match('/DROP\s+(TABLE|COLUMN|DATABASE)|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET|INSERT\s+INTO/i', $sql), 'DDL steps never drop tables/columns nor modify rows');
check(!str_contains($sql, '@'), 'plan text contains no e-mail');
$step = fn(string $id) => array_values(array_filter($plan['steps'], fn($s) => $s['id'] === $id))[0] ?? ['id' => $id, 'kind' => 'missing', 'sql' => ['(step missing)']];
check($step('people-user-id')['sql'] === ['ALTER TABLE people ADD COLUMN user_id INT UNSIGNED NULL AFTER id, ADD UNIQUE KEY unique_person_account (user_id)'], 'people.user_id takes the exact type of users.id');
check($step('people-legacy-nullable')['sql'] === ["ALTER TABLE people MODIFY password varchar(255) COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL, MODIFY role varchar(20) COLLATE utf8mb4_0900_ai_ci NULL DEFAULT 'user'"], 'legacy columns keep type, collation and default');
check($step('people-email-index')['sql'] === ['ALTER TABLE people DROP INDEX `email`, ADD INDEX idx_people_email (email)'], 'people.email: unique dropped and plain index added in one statement');
check($step('companies-name-index')['sql'] === ['ALTER TABLE companies DROP INDEX `name`, ADD INDEX idx_companies_name (name)'], 'companies.name: unique dropped, lookup index kept');
check($step('applications-created-at-alias')['sql'] === ['ALTER TABLE applications ADD COLUMN created_at DATETIME GENERATED ALWAYS AS (application_date) VIRTUAL AFTER application_date'], 'created_at is a DATETIME alias of the real application_date');
check($step('fk-advertisements-created_by')['sql'] === ['ALTER TABLE advertisements DROP FOREIGN KEY `advertisements_ibfk_3`', 'ALTER TABLE advertisements MODIFY created_by int NULL DEFAULT NULL', 'ALTER TABLE advertisements ADD CONSTRAINT fk_ad_creator FOREIGN KEY (created_by) REFERENCES people(id) ON DELETE SET NULL'], 'created_by: drop RESTRICT key, make nullable, add SET NULL key');
check($step('fk-applications-person_id')['sql'][1] === 'ALTER TABLE applications ADD CONSTRAINT fk_application_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE', 'applications.person_id cascades like main');
check(str_contains($step('create-google-identities')['sql'][0], 'user_id INT UNSIGNED NOT NULL'), 'google_identities.user_id matches users.id');
check($step('migrate-accounts')['kind'] === 'data' && $step('migrate-accounts')['sql'] === [], 'account migration is the only data step');
check(count(array_filter($plan['deferred'], fn($d) => str_starts_with($d, 'clear-legacy-passwords'))) === 1 && count(array_filter($plan['deferred'], fn($d) => str_starts_with($d, 'convert-ids-unsigned'))) === 1, 'cleanup and id conversion are deferred, not planned');

// ------------------------------------------------------------------ partial (interrupted) historical migration
$partial = historical();
$partial['tables']['schema_migrations'] = freshCanonical()['tables']['schema_migrations'];
$partial['tables']['users'] = kanan()['tables']['users'];
foreach (['first_name' => 'firstname', 'last_name' => 'lastname'] as $a => $b) { $partial['tables']['people']['columns'][$b] = $partial['tables']['people']['columns'][$a]; unset($partial['tables']['people']['columns'][$a]); }
check(detectShape($partial)['shape'] === 'partial', 'partially migrated database detected');
$pp = planMigration($partial, $accounts);
check(!array_intersect(['create-schema-migrations', 'create-users', 'people-rename-names'], ids($pp)) && ids($pp)[0] === 'people-legacy-nullable', 'resume skips completed steps');
check(verifyCanonical($pp['projected']) === [], 'resumed plan reaches the canonical contract');

// ------------------------------------------------------------------ Kanan and previous main
$kp = planMigration(kanan(), ['account_candidates' => 0]);
foreach (['advertisements-columns', 'applications-application-date-alias', 'applications-unique', 'people-user-id-unique', 'create-company-members', 'create-google-identities', 'fk-advertisements-created_by', 'fk-advertisements-company_id'] as $id) check(in_array($id, ids($kp), true), "Kanan plan includes $id");
check(verifyCanonical($kp['projected']) === [], 'Kanan plan reaches the canonical contract: ' . implode('; ', verifyCanonical($kp['projected'])));
$mp = planMigration(mainShape(), ['account_candidates' => 0]);
check(ids($mp) === ['create-schema-migrations', 'people-email-index', 'companies-name-index', 'fk-advertisements-company_id'], 'previous main: only journal, lookup indexes and company RESTRICT (' . implode(',', ids($mp)) . ')');
check($mp['steps'][3]['sql'] === ['ALTER TABLE advertisements DROP FOREIGN KEY `fk_advertisement_company`', 'ALTER TABLE advertisements ADD CONSTRAINT fk_advertisement_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'], 'company CASCADE replaced by RESTRICT');
check(verifyCanonical($mp['projected']) === [], 'previous main reaches the contract; its TIMESTAMP created_at + generated application_date is an accepted variant');
check(planMigration(freshCanonical())['steps'] === [], 'canonical database: nothing to do');

// ------------------------------------------------------------------ stored application date (generated alias + NOT NULL defect)
check($step('applications-date-not-null-check')['sql'] === ['ALTER TABLE applications MODIFY application_date datetime NULL DEFAULT CURRENT_TIMESTAMP, ADD CONSTRAINT chk_application_date CHECK (application_date IS NOT NULL)'], 'historical application_date: nullable with its default + CHECK, in one statement');
$hp = $plan['projected']['tables']['applications'];
check($hp['columns']['application_date']['nullable'] && $hp['columns']['application_date']['default'] === 'CURRENT_TIMESTAMP' && $hp['columns']['application_date']['type'] === 'datetime' && hasNotNullCheck($plan['projected'], 'applications', 'application_date'), 'projection: application_date keeps type and default, nullable, CHECK enforced');
$bad = freshCanonical(); $bad['tables']['applications']['columns']['application_date']['nullable'] = false; $bad['tables']['applications']['checks'] = [];
check(in_array('applications.application_date is NOT NULL: INSERTs without a date fail with its generated alias', verifyCanonical($bad), true), 'verifyCanonical rejects a NOT NULL stored date next to its alias (previous canonical-v1)');
check(detectShape($bad)['shape'] !== 'canonical' && setupDecision($bad)['action'] === 'refuse', 'setup refuses a database built with the defective schema');
$bp = planMigration($bad, ['account_candidates' => 0]);
check(ids($bp) === ['applications-date-not-null-check'] && verifyCanonical($bp['projected']) === [], 'defective canonical-v1 is repaired by the date step only (' . implode(',', ids($bp)) . ')');
$s = freshCanonical(); $s['tables']['applications']['checks'] = [];
check(verifyCanonical($s) === ['applications.application_date has no enforced CHECK (application_date IS NOT NULL)'], 'verifyCanonical requires the CHECK on a nullable application_date');
check(ids(planMigration($s, ['account_candidates' => 0])) === ['applications-date-not-null-check'] && planMigration($s, ['account_candidates' => 0])['steps'][0]['sql'] === ['ALTER TABLE applications ADD CONSTRAINT chk_application_date CHECK (application_date IS NOT NULL)'], 'missing CHECK only: the step adds the CHECK without MODIFY');
$s['tables']['applications']['checks']['chk_application_date'] = ['clause' => '(`application_date` is not null)', 'enforced' => false];
check(verifyCanonical($s) !== [], 'a NOT ENFORCED CHECK does not count');
foreach (['(`application_date` is not null)', '`application_date` IS NOT NULL', '(application_date is not null)'] as $clause) {
    $s['tables']['applications']['checks'] = ['c' => ['clause' => $clause, 'enforced' => true]];
    check(hasNotNullCheck($s, 'applications', 'application_date'), "CHECK clause recognised: $clause");
}
foreach (['(`application_date` > _utf8mb4\'2000-01-01\')', '(`created_at` is not null)', '(`application_date` is null)'] as $clause) {
    $s['tables']['applications']['checks'] = ['c' => ['clause' => $clause, 'enforced' => true]];
    check(!hasNotNullCheck($s, 'applications', 'application_date'), "other CHECK not taken for NOT NULL: $clause");
}
check(storedApplicationDate(freshCanonical()) === 'application_date' && storedApplicationDate(mainShape()) === 'created_at' && storedApplicationDate(historical()) === null, 'stored date identified per shape');
$s = mainShape(); $s['tables']['applications']['columns']['created_at']['nullable'] = false;
$sp = planMigration($s, ['account_candidates' => 0]);
check((array_values(array_filter($sp['steps'], fn($x) => $x['id'] === 'applications-date-not-null-check'))[0]['sql'] ?? null) === ['ALTER TABLE applications MODIFY created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP'], 'main variant with NOT NULL created_at: made nullable, no CHECK added');
check(verifyCanonical($sp['projected']) === [], 'main variant repaired reaches the contract');
check(!in_array('applications-date-not-null-check', ids(planMigration(kanan(), ['account_candidates' => 0])), true) || verifyCanonical(planMigration(kanan(), ['account_candidates' => 0])['projected']) === [], 'Kanan plan stays consistent with the date rule');

// ------------------------------------------------------------------ refused schemas
$unknown = [];
$s = historical(); unset($s['tables']['applications']); $unknown['missing applications'] = $s;
$s = historical(); $s['tables']['people']['columns']['id'] = c('varchar(36)'); $unknown['non-integer people.id'] = $s;
$s = historical(); $s['tables']['people']['columns']['firstname'] = c('varchar(100)'); $unknown['both name variants'] = $s;
$s = historical(); $s['tables']['applications']['columns']['status'] = c('int'); $unknown['numeric status'] = $s;
$s = historical(); $s['tables']['applications']['columns']['created_at'] = c('datetime'); $unknown['two real date columns'] = $s;
$s = historical(); $s['tables']['applications']['columns']['person_id'] = c('bigint'); $unknown['foreign key type mismatch'] = $s;
foreach ($unknown as $why => $s) { $r = planMigration($s, $accounts); check($r['shape'] === 'unknown' && $r['blockers'] && $r['steps'] === [], "refused: $why"); }
$s = historical(); $s['tables']['users'] = kanan()['tables']['users']; $s['tables']['users']['columns']['id'] = c('bigint unsigned', false, null, 'auto_increment'); $s['tables']['people']['columns']['user_id'] = c('int unsigned', true);
$r = planMigration($s, $accounts); check((bool)array_filter($r['blockers'], fn($b) => str_contains($b, 'people.user_id -> users.id: type mismatch')), 'new foreign key with mismatched types is a blocker');
$r = planMigration(historical(), $accounts + ['blockers' => ['2 orphan row(s) in applications.person_id (no matching people.id)']]);
check(in_array('2 orphan row(s) in applications.person_id (no matching people.id)', planMigration(historical(), ['blockers' => ['2 orphan row(s) in applications.person_id (no matching people.id)']] + $accounts)['blockers'], true), 'data blockers stop the plan');

// ------------------------------------------------------------------ accounts
$row = fn(int $id, string $email, ?string $role = 'user') => ['id' => $id, 'firstname' => 'Fixture', 'lastname' => 'Person', 'email' => $email, 'phone' => null, 'role' => $role];
check(accountBlockers([$row(1, 'a@example.invalid'), $row(2, 'b@example.invalid')], []) === [], 'distinct, valid candidates pass');
$cases = [
    'duplicate e-mail (case)' => [[$row(1, 'a@example.invalid'), $row(2, 'A@Example.invalid')], [], 'same e-mail as profile 1'],
    'surrounding spaces' => [[$row(1, ' a@example.invalid')], [], 'surrounding spaces'],
    'existing account' => [[$row(3, 'c@example.invalid')], ['C@example.invalid'], 'already exists and is not linked'],
    'privileged legacy role' => [[$row(1, 'a@example.invalid', 'admin')], [], 'no role is granted automatically'],
    'malformed e-mail' => [[$row(1, 'not-an-email')], [], 'malformed'],
    'empty first name' => [[['firstname' => ' '] + $row(1, 'a@example.invalid')], [], 'firstname is empty'],
];
foreach ($cases as $why => [$rows, $existing, $needle]) { $b = accountBlockers($rows, $existing); check((bool)array_filter($b, fn($m) => str_contains($m, $needle)), "account blocker: $why"); check(!array_filter($b, fn($m) => str_contains($m, '@')), "account blocker message has no e-mail: $why"); }

// ------------------------------------------------------------------ password formats (synthetic values only)
$pw = 'synthetic-' . bin2hex(random_bytes(6));
$y = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
foreach (['$2y$' => $y, '$2b$' => '$2b$' . substr($y, 4), '$2a$' => '$2a$' . substr($y, 4)] as $label => $h) check(isMigratableBcrypt($h) && password_verify($pw, $h), "bcrypt $label is kept and verifies");
foreach (['cost 03' => '$2y$03$' . substr($y, 7), 'cost 32' => '$2y$32$' . substr($y, 7), 'truncated' => substr($y, 0, 59), '$2x$' => '$2x$' . substr($y, 4), 'plaintext-like' => 'abcdefghijk', 'empty' => '', 'md5-like' => md5('x')] as $label => $h) check(!isMigratableBcrypt($h), "not imported: $label");
check(!isMigratableBcrypt(null) && !isMigratableBcrypt(12345), 'non-strings are not imported');
if (defined('PASSWORD_ARGON2ID')) check(!isMigratableBcrypt(password_hash('x', PASSWORD_ARGON2ID)), 'argon2 is not part of the legacy formats: locked');
$l1 = lockedPasswordHash(); $l2 = lockedPasswordHash();
check($l1 !== $l2 && isLockedPasswordHash($l1) && strlen($l1) <= 255, 'locked value: random, marked, fits password_hash');
check(!password_verify('anything-123456', $l1) && !password_verify($l1, $l1) && !password_verify('', $l1), 'locked value never verifies, not even against itself');
check(!isLockedPasswordHash(password_hash('x', PASSWORD_DEFAULT)), 'a real hash is not reported as locked');

// ------------------------------------------------------------------ definitions and helpers
check(columnDefinition(c('varchar(20)', false, "O'Neil"), true) === "varchar(20) COLLATE utf8mb4_0900_ai_ci NULL DEFAULT 'O''Neil'", 'defaults are quoted and escaped');
check(columnDefinition(c('datetime', false, 'CURRENT_TIMESTAMP', 'default_generated'), false) === 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'expression defaults stay expressions');
check(intType(c('int(11)')) === 'int' && intType(c('INT(10) UNSIGNED')) === 'int unsigned' && intType(c('varchar(10)')) === null, 'integer types normalised for FK comparisons');
check(generatedFrom(c('datetime', true, null, 'VIRTUAL GENERATED', '`application_date`'), 'application_date'), 'generated alias recognised');
check(deleteRule('NO ACTION') === 'RESTRICT', 'NO ACTION counts as RESTRICT');

echo $failures ? "FAIL " . count($failures) . " of $checks schema-migration unit checks:\n  - " . implode("\n  - ", $failures) . "\n" : "PASS $checks schema-migration unit checks (no database used)\n";
exit($failures ? 1 : 0);
