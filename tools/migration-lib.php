<?php
declare(strict_types=1);
// Schema reconciliation engine shared by tools/migrate.php, tools/setup.php and tests/migration.
// Pure functions turn a schema snapshot into findings and a plan; only schemaSnapshot(), preflightData(),
// migrateAccounts() and dataFingerprints() touch the database, and only migrateAccounts() writes.
// Nothing here prints or returns e-mails, names, phones or password values: findings carry row ids only.

const MIGRATION_CANONICAL = 'canonical-v1';
const MIGRATION_CORE_TABLES = ['companies', 'categories', 'people', 'advertisements', 'applications'];
const MIGRATION_STATUSES = ['pending', 'reviewed', 'accepted', 'rejected'];
const MIGRATION_STATUS_TYPE = "enum('pending','reviewed','accepted','rejected')";

// ---------------------------------------------------------------- snapshot (read-only)

/** Tables, columns, indexes and foreign keys of the current database, read from information_schema. */
function schemaSnapshot(PDO $db): array {
    try { $db->exec('SET SESSION information_schema_stats_expiry=0'); } catch (Throwable) {}
    $s = ['database' => (string)$db->query('SELECT DATABASE()')->fetchColumn(), 'tables' => []];
    foreach ($db->query("SELECT TABLE_NAME AS t, TABLE_COLLATION AS c, AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'") as $r)
        $s['tables'][$r['t']] = ['collation' => $r['c'], 'auto_increment' => $r['ai'] === null ? null : (int)$r['ai'], 'columns' => [], 'indexes' => [], 'fks' => [], 'checks' => []];
    foreach ($db->query('SELECT TABLE_NAME AS t, COLUMN_NAME AS n, COLUMN_TYPE AS type, DATA_TYPE AS dt, IS_NULLABLE AS nl, COLUMN_DEFAULT AS def, COLLATION_NAME AS coll, EXTRA AS extra, GENERATION_EXPRESSION AS gen FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
        if (!isset($s['tables'][$r['t']])) continue;
        $s['tables'][$r['t']]['columns'][$r['n']] = ['type' => (string)$r['type'], 'data_type' => strtolower((string)$r['dt']), 'nullable' => $r['nl'] === 'YES', 'default' => $r['def'], 'collation' => $r['coll'], 'extra' => strtolower((string)$r['extra']), 'generation' => (string)$r['gen']];
    }
    foreach ($db->query('SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $r) {
        if (!isset($s['tables'][$r['t']])) continue;
        $s['tables'][$r['t']]['indexes'][$r['i']]['unique'] = (int)$r['nu'] === 0;
        $s['tables'][$r['t']]['indexes'][$r['i']]['columns'][] = $r['c'];
    }
    foreach ($db->query('SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS d FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION') as $r) {
        if (!isset($s['tables'][$r['t']])) continue;
        $fk = $s['tables'][$r['t']]['fks'][$r['n']] ?? ['columns' => [], 'ref_table' => $r['rt'], 'ref_columns' => [], 'on_delete' => strtoupper((string)$r['d'])];
        $fk['columns'][] = $r['c']; $fk['ref_columns'][] = $r['rc'];
        $s['tables'][$r['t']]['fks'][$r['n']] = $fk;
    }
    // CHECK constraints (MySQL 8.0.16+); ENFORCED is MySQL-only, so fall back to "enforced" where it is absent.
    foreach (['tc.ENFORCED', "'YES'"] as $enforced) {
        try {
            foreach ($db->query("SELECT tc.TABLE_NAME AS t, tc.CONSTRAINT_NAME AS n, cc.CHECK_CLAUSE AS clause, $enforced AS e FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME") as $r)
                if (isset($s['tables'][$r['t']])) $s['tables'][$r['t']]['checks'][$r['n']] = ['clause' => (string)$r['clause'], 'enforced' => strtoupper((string)$r['e']) === 'YES'];
            break;
        } catch (PDOException) { foreach ($s['tables'] as &$table) $table['checks'] = []; unset($table); }
    }
    return $s;
}

// ---------------------------------------------------------------- snapshot helpers (pure)

function hasTable(array $s, string $t): bool { return isset($s['tables'][$t]); }
function col(array $s, string $t, string $c): ?array { return $s['tables'][$t]['columns'][$c] ?? null; }
/** 'int', 'int unsigned', 'bigint unsigned'…: display widths removed, so FK type comparisons are exact. */
function intType(?array $c): ?string {
    if (!$c || !in_array($c['data_type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) return null;
    return trim(preg_replace('/\(\d+\)/', '', strtolower($c['type'])));
}
// EXTRA says "VIRTUAL GENERATED"/"STORED GENERATED" for generated columns, but also "DEFAULT_GENERATED" for an
// ordinary column with an expression default (application_date DEFAULT CURRENT_TIMESTAMP): only the former count.
function isGenerated(?array $c): bool { return $c !== null && (bool)preg_match('/\b(virtual|stored) generated\b/', strtolower($c['extra'])); }
function generatedFrom(?array $c, string $source): bool { return isGenerated($c) && str_replace(['`', ' '], '', $c['generation']) === $source; }
/** An enforced CHECK equivalent to "$c IS NOT NULL" (clauses come back as e.g. "(`application_date` is not null)"). */
function hasNotNullCheck(array $s, string $t, string $c): bool {
    foreach ($s['tables'][$t]['checks'] ?? [] as $check)
        if ($check['enforced'] && strtolower(str_replace(['`', ' ', '(', ')'], '', $check['clause'])) === strtolower($c) . 'isnotnull') return true;
    return false;
}
/**
 * The stored applications date: application_date (canonical, with created_at generated from it) or created_at
 * (previous main variant, with application_date generated from it); null when neither alias pair exists.
 */
function storedApplicationDate(array $s): ?string {
    if (generatedFrom(col($s, 'applications', 'created_at'), 'application_date')) return 'application_date';
    if (generatedFrom(col($s, 'applications', 'application_date'), 'created_at')) return 'created_at';
    return null;
}
/** Index names whose columns are exactly $cols (any order when $ordered is false). */
function indexesOn(array $s, string $t, array $cols, ?bool $unique = null, bool $ordered = false): array {
    $names = [];
    foreach ($s['tables'][$t]['indexes'] ?? [] as $name => $ix) {
        $a = $ix['columns']; $b = $cols; if (!$ordered) { sort($a); sort($b); }
        if ($a === $b && ($unique === null || $ix['unique'] === $unique)) $names[] = $name;
    }
    return $names;
}
function hasIndexStartingWith(array $s, string $t, string $c): bool {
    foreach ($s['tables'][$t]['indexes'] ?? [] as $ix) if (($ix['columns'][0] ?? null) === $c) return true;
    return false;
}
function fkOnColumn(array $s, string $t, string $c): ?array {
    foreach ($s['tables'][$t]['fks'] ?? [] as $name => $fk) if ($fk['columns'] === [$c]) return ['name' => $name] + $fk;
    return null;
}
/** InnoDB reports an unspecified ON DELETE as NO ACTION, which behaves as RESTRICT. */
function deleteRule(string $rule): string { return $rule === 'NO ACTION' ? 'RESTRICT' : $rule; }
function quoteIdentifier(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
/** Definition that keeps a column's exact type, collation and default; only nullability changes. */
function columnDefinition(array $c, bool $nullable): string {
    $d = $c['type'] . ($c['collation'] ? ' COLLATE ' . $c['collation'] : '') . ($nullable ? ' NULL' : ' NOT NULL');
    if ($c['default'] !== null) $d .= ' DEFAULT ' . (str_contains($c['extra'], 'default_generated') ? $c['default'] : "'" . str_replace("'", "''", (string)$c['default']) . "'");
    elseif ($nullable) $d .= ' DEFAULT NULL';
    return $d;
}

/** Canonical foreign keys: child table, column, parent table, parent column, ON DELETE rule, constraint name. */
function canonicalForeignKeys(): array {
    return [
        ['people', 'user_id', 'users', 'id', 'SET NULL', 'fk_people_user'],
        ['advertisements', 'company_id', 'companies', 'id', 'RESTRICT', 'fk_advertisement_company'],
        ['advertisements', 'category_id', 'categories', 'id', 'SET NULL', 'fk_advertisement_category'],
        ['advertisements', 'created_by', 'people', 'id', 'SET NULL', 'fk_ad_creator'],
        ['applications', 'advertisement_id', 'advertisements', 'id', 'CASCADE', 'fk_application_advertisement'],
        ['applications', 'person_id', 'people', 'id', 'CASCADE', 'fk_application_person'],
        ['company_members', 'company_id', 'companies', 'id', 'CASCADE', 'fk_member_company'],
        ['company_members', 'person_id', 'people', 'id', 'CASCADE', 'fk_member_person'],
        ['google_identities', 'user_id', 'users', 'id', 'CASCADE', 'fk_google_identity_user'],
    ];
}

// ---------------------------------------------------------------- shape detection and contract (pure)

/** empty | historical | partial | upgradable | canonical | unknown, with the reasons an unknown schema is refused. */
function detectShape(array $s): array {
    if (!$s['tables']) return ['shape' => 'empty', 'problems' => []];
    $problems = [];
    foreach (MIGRATION_CORE_TABLES as $t) if (!hasTable($s, $t)) $problems[] = "missing core table $t";
    if ($problems) return ['shape' => 'unknown', 'problems' => $problems];
    $required = ['companies' => ['id', 'name'], 'categories' => ['id', 'name'], 'people' => ['id', 'email'], 'advertisements' => ['id', 'company_id', 'title', 'short_description', 'description', 'location'], 'applications' => ['id', 'person_id', 'advertisement_id', 'status']];
    foreach ($required as $t => $cols) foreach ($cols as $c) if (!col($s, $t, $c)) $problems[] = "missing column $t.$c";
    foreach (array_merge(MIGRATION_CORE_TABLES, ['users']) as $t) if (hasTable($s, $t) && intType(col($s, $t, 'id')) === null) $problems[] = "$t.id is not an integer column";
    $legacy = col($s, 'people', 'first_name') && col($s, 'people', 'last_name');
    $canonical = col($s, 'people', 'firstname') && col($s, 'people', 'lastname');
    $anyLegacy = col($s, 'people', 'first_name') || col($s, 'people', 'last_name');
    $anyCanonical = col($s, 'people', 'firstname') || col($s, 'people', 'lastname');
    // Exactly one complete pair, never a mix: a half-renamed table needs a human look.
    if ($legacy === $canonical || ($anyLegacy && $anyCanonical) || ($anyLegacy && !$legacy) || ($anyCanonical && !$canonical)) $problems[] = 'people must have exactly one of first_name/last_name or firstname/lastname';
    if (($e = col($s, 'people', 'email')) && !in_array($e['data_type'], ['varchar', 'char'], true)) $problems[] = 'people.email is not a text column';
    if (($st = col($s, 'applications', 'status')) && !in_array($st['data_type'], ['varchar', 'char', 'enum'], true)) $problems[] = 'applications.status is not a text or enum column';
    $ad = col($s, 'applications', 'application_date'); $ca = col($s, 'applications', 'created_at');
    if (!$ad && !$ca) $problems[] = 'applications has neither application_date nor created_at';
    if ($ad && $ca && !generatedFrom($ca, 'application_date') && !generatedFrom($ad, 'created_at')) $problems[] = 'applications.application_date and created_at are both real columns';
    if (($ad && !$ca && isGenerated($ad)) || ($ca && !$ad && isGenerated($ca))) $problems[] = 'applications date column is generated from a missing column';
    foreach ($s['tables'] as $t => $table) foreach ($table['fks'] as $name => $fk) {
        if (count($fk['columns']) !== 1 || !hasTable($s, $fk['ref_table'])) continue;
        $child = intType(col($s, $t, $fk['columns'][0])); $parent = intType(col($s, $fk['ref_table'], $fk['ref_columns'][0]));
        if ($child !== null && $parent !== null && $child !== $parent) $problems[] = "foreign key $t.$name type mismatch ($child vs $parent)";
    }
    if ($problems) return ['shape' => 'unknown', 'problems' => $problems];
    if (!verifyCanonical($s)) return ['shape' => 'canonical', 'problems' => []];
    if ($legacy && !hasTable($s, 'users') && !hasTable($s, 'schema_migrations')) return ['shape' => 'historical', 'problems' => []];
    if ($legacy || col($s, 'people', 'password') || hasTable($s, 'schema_migrations')) return ['shape' => 'partial', 'problems' => []];
    return ['shape' => 'upgradable', 'problems' => []];
}

/** Differences from the canonical contract; [] means the schema conforms (tolerated variants are accepted). */
function verifyCanonical(array $s): array {
    $p = [];
    foreach (array_merge(MIGRATION_CORE_TABLES, ['users', 'company_members', 'google_identities', 'schema_migrations']) as $t) if (!hasTable($s, $t)) $p[] = "table $t missing";
    if ($p) return $p;
    $need = [
        'users' => ['firstname', 'lastname', 'email', 'phone', 'password_hash', 'role', 'created_at', 'updated_at'],
        'people' => ['user_id', 'firstname', 'lastname', 'email', 'phone', 'created_at'],
        'companies' => ['name', 'description', 'email', 'phone', 'website', 'created_at'],
        'advertisements' => ['company_id', 'category_id', 'title', 'short_description', 'description', 'salary', 'location', 'working_time', 'contract_type', 'source', 'external_id', 'source_url', 'created_by', 'created_at', 'updated_at'],
        'applications' => ['advertisement_id', 'person_id', 'message', 'status', 'application_date', 'created_at'],
        'company_members' => ['company_id', 'person_id', 'role'],
        'google_identities' => ['google_sub', 'user_id'],
    ];
    foreach ($need as $t => $cols) foreach ($cols as $c) if (!col($s, $t, $c)) $p[] = "column $t.$c missing";
    if ($p) return $p;
    if (!indexesOn($s, 'users', ['email'], true)) $p[] = 'users.email is not unique';
    if (!indexesOn($s, 'people', ['user_id'], true)) $p[] = 'people.user_id is not unique';
    if (indexesOn($s, 'people', ['email'], true)) $p[] = 'people.email is still unique';
    if (!hasIndexStartingWith($s, 'people', 'email')) $p[] = 'people.email has no lookup index';
    if (indexesOn($s, 'companies', ['name'], true)) $p[] = 'companies.name is still unique';
    if (!hasIndexStartingWith($s, 'companies', 'name')) $p[] = 'companies.name has no lookup index';
    foreach (['password', 'role'] as $c) if (($d = col($s, 'people', $c)) && !$d['nullable']) $p[] = "legacy people.$c is not nullable";
    foreach (['category_id', 'working_time', 'created_by'] as $c) if (!col($s, 'advertisements', $c)['nullable']) $p[] = "advertisements.$c is not nullable";
    if (!indexesOn($s, 'advertisements', ['source', 'external_id'], true)) $p[] = 'advertisements (source, external_id) is not unique';
    $ad = col($s, 'applications', 'application_date'); $ca = col($s, 'applications', 'created_at');
    if (!generatedFrom($ca, 'application_date') && !generatedFrom($ad, 'created_at')) $p[] = 'applications created_at/application_date are not one real column plus its alias';
    // MySQL computes a generated column before applying the default of a NOT NULL DEFAULT CURRENT_TIMESTAMP
    // column it depends on, so an INSERT without a date fails (1292). The stored date is therefore nullable;
    // for the canonical application_date an enforced CHECK keeps NULL out.
    elseif (($stored = storedApplicationDate($s)) && !col($s, 'applications', $stored)['nullable']) $p[] = "applications.$stored is NOT NULL: INSERTs without a date fail with its generated alias";
    elseif ($stored === 'application_date' && !hasNotNullCheck($s, 'applications', 'application_date')) $p[] = 'applications.application_date has no enforced CHECK (application_date IS NOT NULL)';
    if (strtolower(col($s, 'applications', 'status')['type']) !== MIGRATION_STATUS_TYPE) $p[] = 'applications.status is not the canonical enum';
    if (!indexesOn($s, 'applications', ['person_id', 'advertisement_id'], true)) $p[] = 'applications (person_id, advertisement_id) is not unique';
    if (!indexesOn($s, 'company_members', ['company_id', 'person_id'], true)) $p[] = 'company_members (company_id, person_id) is not unique';
    if (!indexesOn($s, 'google_identities', ['user_id'], true)) $p[] = 'google_identities.user_id is not unique';
    foreach (canonicalForeignKeys() as [$t, $c, $rt, $rc, $rule]) {
        $fk = fkOnColumn($s, $t, $c);
        if (!$fk || $fk['ref_table'] !== $rt || $fk['ref_columns'] !== [$rc]) $p[] = "foreign key $t.$c -> $rt.$rc missing";
        elseif (deleteRule($fk['on_delete']) !== $rule) $p[] = "foreign key $t.$c has ON DELETE " . deleteRule($fk['on_delete']) . " instead of $rule";
        elseif (intType(col($s, $t, $c)) !== intType(col($s, $rt, $rc))) $p[] = "foreign key $t.$c type differs from $rt.$rc";
    }
    return $p;
}

// ---------------------------------------------------------------- plan (pure)

/**
 * Ordered, idempotent steps that bring $s to the canonical contract. Each step is planned against the
 * projected result of the previous ones, so the dry run shows the complete plan; the executor recomputes
 * the plan from the real database after every step, which is what makes an interrupted run resumable.
 * $facts comes from preflightData(): data blockers and the number of accounts still to migrate.
 */
function planMigration(array $s, array $facts = []): array {
    $shape = detectShape($s);
    $blockers = $shape['problems'];
    if ($shape['shape'] === 'empty') $blockers[] = 'empty database: install it with tools/setup.php instead';
    $blockers = array_merge($blockers, $facts['blockers'] ?? []);
    if ($shape['shape'] === 'unknown' || $shape['shape'] === 'empty') return ['shape' => $shape['shape'], 'blockers' => $blockers, 'steps' => [], 'deferred' => [], 'projected' => $s];
    $p = $s; $steps = [];
    $emit = function (string $id, string $kind, string $title, array $sql, callable $effect) use (&$steps, &$p): void {
        $steps[] = ['id' => $id, 'kind' => $kind, 'title' => $title, 'sql' => $sql]; $effect($p);
    };
    $addColumn = function (array &$p, string $t, string $c, string $type, bool $nullable, ?string $default = null, string $extra = '', string $generation = ''): void {
        $p['tables'][$t]['columns'][$c] = ['type' => $type, 'data_type' => strtok($type, ' ('), 'nullable' => $nullable, 'default' => $default, 'collation' => null, 'extra' => $extra, 'generation' => $generation];
    };
    $newTable = fn(array $cols, array $indexes = []) => ['collation' => $p['tables']['people']['collation'], 'auto_increment' => null, 'columns' => $cols, 'indexes' => $indexes, 'fks' => [], 'checks' => []];
    $column = fn(string $type, bool $nullable = false, string $extra = '') => ['type' => $type, 'data_type' => strtok($type, ' ('), 'nullable' => $nullable, 'default' => null, 'collation' => null, 'extra' => $extra, 'generation' => ''];
    $collation = $p['tables']['people']['collation'] ?: 'utf8mb4_unicode_ci';

    if (!hasTable($p, 'schema_migrations'))
        $emit('create-schema-migrations', 'ddl', 'Create the migration journal', ["CREATE TABLE schema_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, kind VARCHAR(10) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation"],
            function (&$p) use ($newTable, $column) { $p['tables']['schema_migrations'] = $newTable(['name' => $column('varchar(100)'), 'kind' => $column('varchar(10)'), 'applied_at' => $column('datetime')], ['PRIMARY' => ['unique' => true, 'columns' => ['name']]]); });
    if (!hasTable($p, 'users'))
        $emit('create-users', 'ddl', 'Create users (accounts and password hashes)', ["CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, firstname VARCHAR(100) NOT NULL, lastname VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, phone VARCHAR(30) NULL, password_hash VARCHAR(255) NOT NULL, role ENUM('user','admin') NOT NULL DEFAULT 'user', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_user_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation"],
            function (&$p) use ($newTable, $column) {
                $cols = ['id' => $column('int unsigned', false, 'auto_increment')];
                foreach (['firstname' => 'varchar(100)', 'lastname' => 'varchar(100)', 'email' => 'varchar(150)', 'phone' => 'varchar(30)', 'password_hash' => 'varchar(255)', 'role' => "enum('user','admin')", 'created_at' => 'timestamp', 'updated_at' => 'timestamp'] as $c => $t) $cols[$c] = $column($t, in_array($c, ['phone', 'created_at', 'updated_at'], true));
                $p['tables']['users'] = $newTable($cols, ['PRIMARY' => ['unique' => true, 'columns' => ['id']], 'unique_user_email' => ['unique' => true, 'columns' => ['email']]]);
            });
    $usersId = intType(col($p, 'users', 'id'));

    // people
    if (col($p, 'people', 'first_name'))
        $emit('people-rename-names', 'ddl', 'Rename people.first_name/last_name to firstname/lastname (data kept)', ['ALTER TABLE people RENAME COLUMN first_name TO firstname, RENAME COLUMN last_name TO lastname'],
            function (&$p) { foreach (['first_name' => 'firstname', 'last_name' => 'lastname'] as $a => $b) { $p['tables']['people']['columns'][$b] = $p['tables']['people']['columns'][$a]; unset($p['tables']['people']['columns'][$a]); } });
    $mods = [];
    foreach (['password', 'role'] as $c) if (($d = col($p, 'people', $c)) && !$d['nullable']) $mods[$c] = 'MODIFY ' . $c . ' ' . columnDefinition($d, true);
    if ($mods)
        $emit('people-legacy-nullable', 'ddl', 'Make legacy people.' . implode('/', array_keys($mods)) . ' nullable (values kept, cleanup deferred)', ['ALTER TABLE people ' . implode(', ', $mods)],
            function (&$p) use ($mods) { foreach (array_keys($mods) as $c) $p['tables']['people']['columns'][$c]['nullable'] = true; });
    if (!col($p, 'people', 'user_id'))
        $emit('people-user-id', 'ddl', 'Add people.user_id (one account per profile, NULL for guests)', ['ALTER TABLE people ADD COLUMN user_id ' . strtoupper($usersId) . ' NULL AFTER id, ADD UNIQUE KEY unique_person_account (user_id)'],
            function (&$p) use ($addColumn, $usersId) { $addColumn($p, 'people', 'user_id', $usersId, true); $p['tables']['people']['indexes']['unique_person_account'] = ['unique' => true, 'columns' => ['user_id']]; });
    elseif (!indexesOn($p, 'people', ['user_id'], true))
        $emit('people-user-id-unique', 'ddl', 'Make people.user_id unique', ['ALTER TABLE people ADD UNIQUE KEY unique_person_account (user_id)'],
            function (&$p) { $p['tables']['people']['indexes']['unique_person_account'] = ['unique' => true, 'columns' => ['user_id']]; });
    if (!col($p, 'people', 'created_at'))
        $emit('people-created-at', 'ddl', 'Add people.created_at (existing rows get the migration time)', ['ALTER TABLE people ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'],
            function (&$p) use ($addColumn) { $addColumn($p, 'people', 'created_at', 'timestamp', true, 'CURRENT_TIMESTAMP', 'default_generated'); });
    if (col($p, 'people', 'password') && ($facts['account_candidates'] ?? 0) > 0)
        $emit('migrate-accounts', 'data', 'Create one account per profile holding a legacy password (bcrypt kept, anything else locked)', [], function (&$p) {});
    $uniqueEmail = indexesOn($p, 'people', ['email'], true);
    $parts = array_map(fn($n) => 'DROP INDEX ' . quoteIdentifier($n), $uniqueEmail);
    if (!hasIndexStartingWith(['tables' => ['people' => ['indexes' => array_diff_key($p['tables']['people']['indexes'], array_flip($uniqueEmail))]]], 'people', 'email')) $parts[] = 'ADD INDEX idx_people_email (email)';
    if ($parts)
        $emit('people-email-index', 'ddl', 'people.email: guest profiles may share an address (plain index, no uniqueness)', ['ALTER TABLE people ' . implode(', ', $parts)],
            function (&$p) use ($uniqueEmail) { foreach ($uniqueEmail as $n) unset($p['tables']['people']['indexes'][$n]); if (!hasIndexStartingWith($p, 'people', 'email')) $p['tables']['people']['indexes']['idx_people_email'] = ['unique' => false, 'columns' => ['email']]; });

    // companies
    $add = [];
    foreach (['description' => 'TEXT NULL', 'email' => 'VARCHAR(150) NULL', 'phone' => 'VARCHAR(30) NULL', 'website' => 'VARCHAR(255) NULL', 'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $def) if (!col($p, 'companies', $c)) $add[$c] = "ADD COLUMN $c $def";
    if ($add)
        $emit('companies-columns', 'ddl', 'Add companies.' . implode('/', array_keys($add)), ['ALTER TABLE companies ' . implode(', ', $add)],
            function (&$p) use ($add, $addColumn) { foreach (array_keys($add) as $c) $addColumn($p, 'companies', $c, ['description' => 'text', 'email' => 'varchar(150)', 'phone' => 'varchar(30)', 'website' => 'varchar(255)', 'created_at' => 'timestamp'][$c], true); });
    $uniqueName = indexesOn($p, 'companies', ['name'], true);
    $parts = array_map(fn($n) => 'DROP INDEX ' . quoteIdentifier($n), $uniqueName);
    if (!hasIndexStartingWith(['tables' => ['companies' => ['indexes' => array_diff_key($p['tables']['companies']['indexes'], array_flip($uniqueName))]]], 'companies', 'name')) $parts[] = 'ADD INDEX idx_companies_name (name)';
    if ($parts)
        $emit('companies-name-index', 'ddl', 'companies.name: homonyms allowed, plain lookup index kept', ['ALTER TABLE companies ' . implode(', ', $parts)],
            function (&$p) use ($uniqueName) { foreach ($uniqueName as $n) unset($p['tables']['companies']['indexes'][$n]); if (!hasIndexStartingWith($p, 'companies', 'name')) $p['tables']['companies']['indexes']['idx_companies_name'] = ['unique' => false, 'columns' => ['name']]; });

    // advertisements
    $peopleId = intType(col($p, 'people', 'id'));
    $defs = ['contract_type' => ['VARCHAR(100) NULL', 'varchar(100)'], 'source' => ["ENUM('LOCAL','FRANCE_TRAVAIL') NOT NULL DEFAULT 'LOCAL'", "enum('local','france_travail')"], 'external_id' => ['VARCHAR(100) NULL', 'varchar(100)'], 'source_url' => ['VARCHAR(500) NULL', 'varchar(500)'], 'created_by' => [strtoupper($peopleId) . ' NULL', $peopleId], 'created_at' => ['TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp'], 'updated_at' => ['TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp']];
    $add = [];
    foreach ($defs as $c => [$def]) if (!col($p, 'advertisements', $c)) $add[$c] = "ADD COLUMN $c $def";
    if ($add)
        $emit('advertisements-columns', 'ddl', 'Add advertisements.' . implode('/', array_keys($add)), ['ALTER TABLE advertisements ' . implode(', ', $add)],
            function (&$p) use ($add, $defs, $addColumn) { foreach (array_keys($add) as $c) $addColumn($p, 'advertisements', $c, $defs[$c][1], $c !== 'source'); });
    if (($w = col($p, 'advertisements', 'working_time')) && !$w['nullable'])
        $emit('advertisements-working-time-nullable', 'ddl', 'Make advertisements.working_time nullable (imports may omit it)', ['ALTER TABLE advertisements MODIFY working_time ' . columnDefinition($w, true)],
            function (&$p) { $p['tables']['advertisements']['columns']['working_time']['nullable'] = true; });
    if (!indexesOn($p, 'advertisements', ['source', 'external_id'], true))
        $emit('advertisements-external-unique', 'ddl', 'Unique imported offer per (source, external_id)', ['ALTER TABLE advertisements ADD UNIQUE KEY unique_external_job (source, external_id)'],
            function (&$p) { $p['tables']['advertisements']['indexes']['unique_external_job'] = ['unique' => true, 'columns' => ['source', 'external_id']]; });

    // applications
    $ad = col($p, 'applications', 'application_date'); $ca = col($p, 'applications', 'created_at');
    // The stored date is made nullable (plus an enforced CHECK for application_date) BEFORE any alias exists:
    // with a NOT NULL stored date, MySQL rejects INSERTs that omit it once a generated column depends on it.
    $storedDate = ($ad && !isGenerated($ad) && (!$ca || generatedFrom($ca, 'application_date'))) ? 'application_date'
        : (($ca && !isGenerated($ca) && (!$ad || generatedFrom($ad, 'created_at'))) ? 'created_at' : null);
    if ($storedDate) {
        $sd = col($p, 'applications', $storedDate); $parts = [];
        if (!$sd['nullable']) $parts[] = "MODIFY $storedDate " . columnDefinition($sd, true);
        $needsCheck = $storedDate === 'application_date' && !hasNotNullCheck($p, 'applications', 'application_date');
        if ($needsCheck) $parts[] = 'ADD CONSTRAINT chk_application_date CHECK (application_date IS NOT NULL)';
        if ($parts)
            $emit('applications-date-not-null-check', 'ddl', "applications.$storedDate nullable with its default" . ($needsCheck ? ' and CHECK (application_date IS NOT NULL)' : '') . ' (dates kept; INSERTs without a date keep working with the alias)', ['ALTER TABLE applications ' . implode(', ', $parts)],
                function (&$p) use ($storedDate, $needsCheck) { $p['tables']['applications']['columns'][$storedDate]['nullable'] = true; if ($needsCheck) $p['tables']['applications']['checks']['chk_application_date'] = ['clause' => '(`application_date` is not null)', 'enforced' => true]; });
        $ad = col($p, 'applications', 'application_date'); $ca = col($p, 'applications', 'created_at');
    }
    if ($ad && !$ca)
        $emit('applications-created-at-alias', 'ddl', 'Add applications.created_at as a generated alias of application_date (no data moved)', ['ALTER TABLE applications ADD COLUMN created_at ' . strtoupper($ad['type']) . ' GENERATED ALWAYS AS (application_date) VIRTUAL AFTER application_date'],
            function (&$p) use ($ad, $addColumn) { $addColumn($p, 'applications', 'created_at', $ad['type'], true, null, 'virtual generated', '`application_date`'); });
    elseif ($ca && !$ad)
        $emit('applications-application-date-alias', 'ddl', 'Add applications.application_date as a generated alias of created_at (no data moved)', ['ALTER TABLE applications ADD COLUMN application_date ' . strtoupper($ca['type']) . ' GENERATED ALWAYS AS (created_at) VIRTUAL'],
            function (&$p) use ($ca, $addColumn) { $addColumn($p, 'applications', 'application_date', $ca['type'], true, null, 'virtual generated', '`created_at`'); });
    if (strtolower(col($p, 'applications', 'status')['type']) !== MIGRATION_STATUS_TYPE)
        $emit('applications-status-enum', 'ddl', 'applications.status as ENUM(pending, reviewed, accepted, rejected)', ["ALTER TABLE applications MODIFY status ENUM('pending','reviewed','accepted','rejected') NOT NULL DEFAULT 'pending'"],
            function (&$p) { $p['tables']['applications']['columns']['status'] = ['type' => MIGRATION_STATUS_TYPE, 'data_type' => 'enum', 'nullable' => false, 'default' => 'pending', 'collation' => null, 'extra' => '', 'generation' => '']; });
    if (!indexesOn($p, 'applications', ['person_id', 'advertisement_id'], true))
        $emit('applications-unique', 'ddl', 'One application per (person, advertisement)', ['ALTER TABLE applications ADD UNIQUE KEY unique_person_ad (person_id, advertisement_id)'],
            function (&$p) { $p['tables']['applications']['indexes']['unique_person_ad'] = ['unique' => true, 'columns' => ['person_id', 'advertisement_id']]; });

    // company_members and google_identities
    $companyId = intType(col($p, 'companies', 'id'));
    if (!hasTable($p, 'company_members'))
        $emit('create-company-members', 'ddl', 'Create company_members (recruiters per company)', ['CREATE TABLE company_members (company_id ' . strtoupper($companyId) . ' NOT NULL, person_id ' . strtoupper($peopleId) . " NOT NULL, role ENUM('recruiter') NOT NULL DEFAULT 'recruiter', PRIMARY KEY (company_id, person_id), KEY idx_member_person (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation"],
            function (&$p) use ($newTable, $column, $companyId, $peopleId) { $p['tables']['company_members'] = $newTable(['company_id' => $column($companyId), 'person_id' => $column($peopleId), 'role' => $column("enum('recruiter')")], ['PRIMARY' => ['unique' => true, 'columns' => ['company_id', 'person_id']], 'idx_member_person' => ['unique' => false, 'columns' => ['person_id']]]); });
    elseif (!indexesOn($p, 'company_members', ['company_id', 'person_id'], true))
        $emit('company-members-unique', 'ddl', 'One membership per (company, person)', ['ALTER TABLE company_members ADD UNIQUE KEY unique_company_person (company_id, person_id)'],
            function (&$p) { $p['tables']['company_members']['indexes']['unique_company_person'] = ['unique' => true, 'columns' => ['company_id', 'person_id']]; });
    if (!hasTable($p, 'google_identities'))
        $emit('create-google-identities', 'ddl', 'Create google_identities (explicit Google links only)', ['CREATE TABLE google_identities (google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, user_id ' . strtoupper($usersId) . " NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_google_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation"],
            function (&$p) use ($newTable, $column, $usersId) { $p['tables']['google_identities'] = $newTable(['google_sub' => $column('varchar(255)'), 'user_id' => $column($usersId), 'created_at' => $column('timestamp')], ['PRIMARY' => ['unique' => true, 'columns' => ['google_sub']], 'unique_google_user' => ['unique' => true, 'columns' => ['user_id']]]); });

    // Foreign keys last: every column and table they need exists by now. Changing a rule is DROP then ADD
    // (MySQL cannot do both in one copying ALTER); a crash in between is resumed by adding the missing key.
    foreach (canonicalForeignKeys() as [$t, $c, $rt, $rc, $rule, $name]) {
        if (!col($p, $t, $c) || !col($p, $rt, $rc)) continue;
        $child = intType(col($p, $t, $c)); $parent = intType(col($p, $rt, $rc));
        if ($child !== $parent) { $blockers[] = "foreign key $t.$c -> $rt.$rc: type mismatch ($child vs $parent)"; continue; }
        $fk = fkOnColumn($p, $t, $c);
        if ($fk && $fk['ref_table'] === $rt && $fk['ref_columns'] === [$rc] && deleteRule($fk['on_delete']) === $rule) continue;
        $sql = [];
        if ($fk) $sql[] = "ALTER TABLE $t DROP FOREIGN KEY " . quoteIdentifier($fk['name']);
        $cd = col($p, $t, $c);
        if ($rule === 'SET NULL' && !$cd['nullable']) $sql[] = "ALTER TABLE $t MODIFY $c " . columnDefinition($cd, true);
        $sql[] = "ALTER TABLE $t ADD CONSTRAINT $name FOREIGN KEY ($c) REFERENCES $rt($rc) ON DELETE $rule";
        $emit("fk-$t-$c", 'ddl', "Foreign key $t.$c -> $rt.$rc ON DELETE $rule", $sql,
            function (&$p) use ($t, $c, $rt, $rc, $rule, $name, $fk) {
                if ($fk) unset($p['tables'][$t]['fks'][$fk['name']]);
                if ($rule === 'SET NULL') $p['tables'][$t]['columns'][$c]['nullable'] = true;
                $p['tables'][$t]['fks'][$name] = ['columns' => [$c], 'ref_table' => $rt, 'ref_columns' => [$rc], 'on_delete' => $rule];
            });
    }
    return ['shape' => $shape['shape'], 'blockers' => array_values(array_unique($blockers)), 'steps' => $steps, 'deferred' => deferredOperations($p), 'projected' => $p];
}

/** Cleanups that this tool reports but never runs; each needs its own validated decision. */
function deferredOperations(array $s): array {
    $d = [];
    if (col($s, 'people', 'password')) {
        $d[] = 'clear-legacy-passwords: set people.password to NULL once accounts are validated (start with locked accounts)';
        $d[] = 'drop-legacy-people-columns: drop people.password and people.role after the cleanup above';
    }
    foreach (MIGRATION_CORE_TABLES as $t) if (hasTable($s, $t) && !str_contains((string)intType(col($s, $t, 'id')), 'unsigned')) { $d[] = 'convert-ids-unsigned: convert signed INT identifiers and their foreign keys to INT UNSIGNED (application stopped)'; break; }
    if (col($s, 'company_members', 'id')) $d[] = 'company-members-surrogate-id: optional; the historical id column is kept and (company_id, person_id) is unique';
    return $d;
}

/** What tools/setup.php may do with a database: install (empty), nothing to change (canonical), or refuse. */
function setupDecision(array $s): array {
    if (!$s['tables']) return ['action' => 'install', 'problems' => []];
    $problems = verifyCanonical($s);
    return $problems ? ['action' => 'refuse', 'problems' => $problems] : ['action' => 'canonical', 'problems' => []];
}

// ---------------------------------------------------------------- accounts (pure)

/** Characters in a UTF-8 string, via iconv like the rest of the backend (mbstring is optional); invalid UTF-8 counts as too long. */
function textLength(string $v): int { $n = iconv_strlen($v, 'UTF-8'); return $n === false ? PHP_INT_MAX : $n; }

/** A legacy value is kept only if it is a well-formed bcrypt hash, a format password_verify() accepts. */
function isMigratableBcrypt(mixed $value): bool {
    return is_string($value) && preg_match('/^\$2[aby]\$(0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $value) === 1;
}
/** Not a password hash at all: password_verify() rejects it for any input, and the "!" prefix marks a reset to do. */
function lockedPasswordHash(): string { return '!locked:' . bin2hex(random_bytes(16)); }
function isLockedPasswordHash(string $hash): bool { return str_starts_with($hash, '!'); }

/**
 * Reasons that stop the account migration, by profile id only. $candidates: rows with id, firstname,
 * lastname, email, phone and role (legacy); $existingEmails: e-mails already in users. Nothing is ever
 * merged or linked by e-mail: any overlap is a blocker for a human to resolve.
 */
function accountBlockers(array $candidates, array $existingEmails): array {
    $b = []; $seen = []; $existing = array_flip(array_map(fn($e) => strtolower(trim((string)$e)), $existingEmails));
    foreach ($candidates as $r) {
        $id = (int)$r['id']; $email = (string)$r['email']; $norm = strtolower(trim($email));
        if ($email !== trim($email)) $b[] = "profile $id: e-mail has surrounding spaces";
        if (strlen($email) > 150 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $b[] = "profile $id: e-mail is malformed or longer than 150 characters";
        if (isset($seen[$norm])) $b[] = "profile $id: same e-mail as profile {$seen[$norm]}";
        $seen[$norm] ??= $id;
        if (isset($existing[$norm])) $b[] = "profile $id: an account with this e-mail already exists and is not linked to it";
        if (!in_array($r['role'] ?? null, [null, 'user'], true)) $b[] = "profile $id: legacy role is not \"user\" (no role is granted automatically)";
        foreach (['firstname' => 100, 'lastname' => 100] as $c => $max) if (!is_string($r[$c] ?? null) || trim($r[$c]) === '' || textLength($r[$c]) > $max) $b[] = "profile $id: $c is empty or longer than $max characters";
        if (($r['phone'] ?? null) !== null && textLength((string)$r['phone']) > 30) $b[] = "profile $id: phone longer than 30 characters";
    }
    return $b;
}

// ---------------------------------------------------------------- data checks and data migration (database)

/** Read-only data checks; returns blockers plus account counts. Never returns personal data. */
function preflightData(PDO $db, array $s): array {
    $blockers = []; $facts = ['account_candidates' => 0, 'bcrypt' => 0, 'locked_ids' => []];
    $count = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    foreach (canonicalForeignKeys() as [$t, $c, $rt, $rc]) {
        if (!col($s, $t, $c) || !col($s, $rt, $rc)) continue;
        $n = $count("SELECT COUNT(*) FROM $t x LEFT JOIN $rt y ON y.$rc=x.$c WHERE x.$c IS NOT NULL AND y.$rc IS NULL");
        if ($n) $blockers[] = "$n orphan row(s) in $t.$c (no matching $rt.$rc)";
    }
    $dups = fn(string $t, string $cols, string $where = '') => $count("SELECT COUNT(*) FROM (SELECT 1 FROM $t $where GROUP BY $cols HAVING COUNT(*)>1) d");
    if (col($s, 'people', 'user_id') && !indexesOn($s, 'people', ['user_id'], true) && ($n = $dups('people', 'user_id', 'WHERE user_id IS NOT NULL'))) $blockers[] = "$n account(s) linked to several profiles";
    if (!indexesOn($s, 'applications', ['person_id', 'advertisement_id'], true) && ($n = $dups('applications', 'person_id, advertisement_id'))) $blockers[] = "$n duplicated (person, advertisement) application pair(s)";
    if (hasTable($s, 'company_members') && !indexesOn($s, 'company_members', ['company_id', 'person_id'], true) && ($n = $dups('company_members', 'company_id, person_id'))) $blockers[] = "$n duplicated (company, person) membership pair(s)";
    if (col($s, 'advertisements', 'external_id') && col($s, 'advertisements', 'source') && !indexesOn($s, 'advertisements', ['source', 'external_id'], true) && ($n = $dups('advertisements', 'source, external_id', 'WHERE external_id IS NOT NULL'))) $blockers[] = "$n duplicated imported offer(s)";
    if (strtolower(col($s, 'applications', 'status')['type']) !== MIGRATION_STATUS_TYPE && ($n = $count("SELECT COUNT(*) FROM applications WHERE status IS NULL OR status NOT IN ('pending','reviewed','accepted','rejected')"))) $blockers[] = "$n application(s) with a status outside pending/reviewed/accepted/rejected";
    if (($ad = col($s, 'applications', 'application_date')) && !isGenerated($ad) && !hasNotNullCheck($s, 'applications', 'application_date') && ($n = $count('SELECT COUNT(*) FROM applications WHERE application_date IS NULL'))) $blockers[] = "$n application(s) without application_date (CHECK application_date IS NOT NULL cannot be added)";
    if (col($s, 'people', 'password')) {
        $first = col($s, 'people', 'firstname') ? 'firstname' : 'first_name'; $last = col($s, 'people', 'lastname') ? 'lastname' : 'last_name';
        $role = col($s, 'people', 'role') ? 'role' : 'NULL';
        $rows = $db->query("SELECT id, $first AS firstname, $last AS lastname, email, phone, $role AS role, password FROM people WHERE password IS NOT NULL" . (col($s, 'people', 'user_id') ? ' AND user_id IS NULL' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $emails = hasTable($s, 'users') ? $db->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN) : [];
        $blockers = array_merge($blockers, accountBlockers($rows, $emails));
        $facts['account_candidates'] = count($rows);
        foreach ($rows as $r) if (isMigratableBcrypt($r['password'])) $facts['bcrypt']++; else $facts['locked_ids'][] = (int)$r['id'];
        unset($rows);
    }
    return $facts + ['blockers' => $blockers];
}

/** The data step: one transaction creating accounts and linking profiles; profile ids never change. */
function migrateAccounts(PDO $db): array {
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT id, firstname, lastname, email, phone, role, password FROM people WHERE password IS NOT NULL AND user_id IS NULL ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $emails = $db->query('SELECT email FROM users FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if ($blockers = accountBlockers($rows, $emails)) throw new RuntimeException('Account migration blocked: ' . implode('; ', $blockers));
        $insert = $db->prepare("INSERT INTO users (firstname, lastname, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, 'user')");
        $link = $db->prepare('UPDATE people SET user_id=? WHERE id=? AND user_id IS NULL');
        $result = ['bcrypt' => 0, 'locked_ids' => []];
        foreach ($rows as $r) {
            $kept = isMigratableBcrypt($r['password']);
            $insert->execute([$r['firstname'], $r['lastname'], $r['email'], $r['phone'], $kept ? $r['password'] : lockedPasswordHash()]);
            $link->execute([(int)$db->lastInsertId(), $r['id']]);
            if ($link->rowCount() !== 1) throw new RuntimeException("Profile {$r['id']} changed during the migration");
            if ($kept) $result['bcrypt']++; else $result['locked_ids'][] = (int)$r['id'];
        }
        unset($rows);
        $db->commit();
        return $result;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** Count, id list, row-content hash and AUTO_INCREMENT per historical table, to prove the migration kept them. */
function dataFingerprints(PDO $db, array $s): array {
    $logical = [
        'people' => ['id', ['firstname', 'first_name'], ['lastname', 'last_name'], 'email', 'phone', 'password', 'role'],
        'companies' => ['id', 'name'], 'categories' => ['id', 'name'],
        'advertisements' => ['id', 'company_id', 'category_id', 'created_by', 'title', 'short_description', 'description', 'salary', 'location', 'working_time'],
        'applications' => ['id', 'person_id', 'advertisement_id', 'message', 'status', ['application_date', 'created_at']],
        'company_members' => ['id', 'company_id', 'person_id', 'role'],
    ];
    $out = [];
    foreach ($logical as $t => $cols) {
        if (!hasTable($s, $t)) continue;
        $select = [];
        foreach ($cols as $c) foreach ((array)$c as $alt) if (col($s, $t, $alt) && !(is_array($c) && isGenerated(col($s, $t, $alt)))) { $select[] = $alt; break; }
        $order = col($s, $t, 'id') ? 'id' : 'company_id, person_id';
        $rows = $db->query('SELECT ' . implode(', ', $select) . " FROM $t ORDER BY $order")->fetchAll(PDO::FETCH_NUM);
        $out[$t] = ['count' => count($rows), 'ids' => col($s, $t, 'id') ? hash('sha256', implode(',', array_column($rows, 0))) : null, 'rows' => hash('sha256', json_encode(array_map(fn($r) => array_map(fn($v) => $v === null ? null : (string)$v, $r), $rows), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)), 'max_id' => col($s, $t, 'id') ? (int)$db->query("SELECT COALESCE(MAX(id),0) FROM $t")->fetchColumn() : null, 'auto_increment' => $s['tables'][$t]['auto_increment']];
        unset($rows);
    }
    return $out;
}
