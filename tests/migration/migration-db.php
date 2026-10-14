<?php
declare(strict_types=1);
// Database-level migration tests. WRITES to a disposable database and empties it between scenarios.
//   MIGRATION_TEST_DB=1 DB_NAME=<name ending _migration_test> php tests/migration/migration-db.php
// Run with setup credentials (CREATE/ALTER/INDEX/REFERENCES/DROP on that database only). Prints ids and counts only.
require __DIR__.'/fixtures.php';
require_once dirname(__DIR__, 2).'/api/integration/common.php';

$db = migrationTestGuard();
$name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$root = dirname(__DIR__, 2);
$checks = 0; $failures = [];
function check(bool $ok, string $what): void { global $checks, $failures; $checks++; if (!$ok) { $failures[] = $what; echo "  FAIL $what\n"; } }
/** Runs a PHP tool as a separate process; returns [exit code, stdout, stderr]. */
function tool(array $args, array $env = [], string $stdin = ''): array {
    global $root;
    $p = proc_open([PHP_BINARY, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + getenv());
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}
function state(PDO $db): array { $s = schemaSnapshot($db); return ['schema' => $s, 'data' => dataFingerprints($db, $s)]; }
function structure(array $s): array { foreach ($s['tables'] as &$t) unset($t['auto_increment']); return $s['tables']; }
$migrate = fn(array $extra = [], array $env = []) => tool(['tools/migrate.php', ...$extra], $env);
$apply = fn(array $env = []) => $migrate(['--apply', "--database=$name"], $env);
$clean = fn(string $o) => !str_contains($o, '@') && !preg_match('/\$2[aby]\$/', $o);
$fresh = function () use ($db): array { resetTestDatabase($db); return buildHistoricalFixture($db); };
$tx = function (callable $f) use ($db) { $db->beginTransaction(); try { return $f(); } finally { $db->rollBack(); } };
/** Error number of a statement expected to fail, 0 if it succeeded. */
$fails = function (string $sql) use ($db): int { try { $db->exec($sql); return 0; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? -1); } };
/** application_date: omitted on INSERT -> current date, explicit NULL and UPDATE to NULL rejected by the CHECK (3819). */
$dateRules = function (int $person, int $ad, string $where) use ($db, $fails) {
    $db->exec("INSERT INTO applications (person_id, advertisement_id, message) VALUES ($person, $ad, 'no date given')");
    $id = (int)$db->lastInsertId();
    $r = $db->query("SELECT application_date, created_at, TIMESTAMPDIFF(SECOND, application_date, NOW()) AS age FROM applications WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    check($r['application_date'] !== null && $r['created_at'] === $r['application_date'] && abs((int)$r['age']) < 300, "$where: INSERT without a date gets the current date, created_at follows");
    check($fails("INSERT INTO applications (person_id, advertisement_id, application_date) VALUES ($person, $ad, NULL)") === 3819, "$where: explicit NULL application_date rejected (CHECK)");
    check($fails("UPDATE applications SET application_date=NULL WHERE id=$id") === 3819 && $db->query("SELECT application_date FROM applications WHERE id=$id")->fetchColumn() === $r['application_date'], "$where: UPDATE to NULL rejected, date unchanged");
};

echo "Migration DB tests on $name\n";

// 1. Fresh install through setup.php, then idempotent setup and demo seed.
resetTestDatabase($db);
[$code, $out] = tool(['tools/setup.php']); check($code === 0 && str_contains($out, 'Canonical schema installed'), 'setup installs an empty database');
check(verifyCanonical(schemaSnapshot($db)) === [], 'installed schema follows the canonical contract');
[$code, $out] = tool(['tools/setup.php']); check($code === 0 && str_contains($out, 'nothing to install'), 'setup on a canonical database changes nothing');
[$code] = tool(['tools/setup.php', '--demo']); check($code === 0 && (int)$db->query('SELECT COUNT(*) FROM advertisements')->fetchColumn() === 20, 'demo seed on the fresh schema');
[$code, $out] = $migrate(); check($code === 0 && str_contains($out, 'Nothing to do'), 'migrate: nothing to do on a fresh install');
check(hasNotNullCheck(schemaSnapshot($db), 'applications', 'application_date') && col(schemaSnapshot($db), 'applications', 'application_date')['nullable'], 'fresh install: application_date nullable with an enforced CHECK (detected from information_schema)');
$tx(function () use ($db, $dateRules) {
    $db->exec("INSERT INTO people (firstname, lastname, email) VALUES ('Date', 'Probe', 'date-probe@example.invalid')");
    $dateRules((int)$db->lastInsertId(), (int)$db->query('SELECT MIN(id) FROM advertisements')->fetchColumn(), 'fresh install');
});

// 2. setup.php refuses the historical database without touching it.
$fx = $fresh(); $before = state($db);
$datesQuery = 'SELECT id, application_date FROM applications ORDER BY id';
$datesBefore = $db->query($datesQuery)->fetchAll(PDO::FETCH_KEY_PAIR);
check($datesBefore == [1 => '2026-10-07 10:27:24', 2 => '2026-10-07 10:27:24', 3 => '2026-10-09 16:47:47', 5 => '2026-10-12 12:08:59'], 'historical fixture dates as expected before migration');
[$code, , $err] = tool(['tools/setup.php']);
check($code === 1 && str_contains($err, 'Setup refused'), 'setup refuses the historical database');
check(state($db) === $before, 'refused setup left schema and data identical (no partial change)');

// 3. Dry run writes nothing, shows the plan and no personal data.
[$code, $out] = $migrate();
check($code === 0 && str_contains($out, 'Shape: historical') && str_contains($out, 'migrate-accounts'), 'dry run reports the historical shape and the plan');
check(str_contains($out, '3 bcrypt hash(es) kept') && str_contains($out, 'profile ids 4'), 'dry run reports kept hashes and the locked profile by id');
check($clean($out), 'dry run output has no e-mail and no hash');
check(state($db) === $before, 'dry run wrote nothing');

// 4. Apply guards.
check($migrate(['--apply'])[0] === 2 && state($db) === $before, 'apply without --database refused, nothing changed');
check($migrate(['--apply', '--database=another_db'])[0] === 2 && state($db) === $before, 'apply with another database name refused');

// 5. Apply on the historical fixture.
[$code, $out] = $apply();
check($code === 0 && str_contains($out, 'Done: canonical schema'), 'apply completes (' . trim(substr($out, -200)) . ')');
check($clean($out), 'apply output has no e-mail and no hash');
$after = state($db);
check(verifyCanonical($after['schema']) === [], 'migrated database follows the canonical contract');
foreach ($before['data'] as $t => $f) foreach (['count', 'ids', 'rows', 'max_id', 'auto_increment'] as $k) check(($after['data'][$t][$k] ?? null) === $f[$k], "$t.$k preserved");
check($db->query('SELECT GROUP_CONCAT(id ORDER BY id) FROM applications')->fetchColumn() === '1,2,3,5', 'application ids 1,2,3,5 kept (no renumbering)');
check($db->query($datesQuery)->fetchAll(PDO::FETCH_KEY_PAIR) === $datesBefore && $db->query('SELECT id, created_at FROM applications ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $datesBefore, 'existing applications keep their exact dates; created_at shows the same values');
check(hasNotNullCheck($after['schema'], 'applications', 'application_date') && $after['schema']['tables']['applications']['checks'] === schemaSnapshot($db)['tables']['applications']['checks'], 'migrated: CHECK (application_date IS NOT NULL) present and enforced');
check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4 && (int)$db->query('SELECT COUNT(DISTINCT user_id) FROM people WHERE user_id IS NOT NULL')->fetchColumn() === 4, 'four accounts, each linked to exactly one profile');
check($db->query("SELECT COUNT(*) FROM users WHERE role<>'user'")->fetchColumn() == 0, 'no privileged role granted');
$hash = fn(int $person) => (string)$db->query("SELECT u.password_hash FROM users u JOIN people p ON p.user_id=u.id WHERE p.id=$person")->fetchColumn();
foreach ($fx['passwords'] as $person => $pw) check(password_verify($pw, $hash($person)), "profile $person: migrated bcrypt hash verifies its synthetic password");
check(str_starts_with($hash(2), '$2b$'), 'the $2b$ hash is imported unchanged (rehashed by the next login)');
check(isLockedPasswordHash($hash(4)) && !password_verify('anything-123456', $hash(4)), 'profile 4: unrecognised value not imported, account locked');
check($db->query("SELECT COUNT(*) FROM people WHERE password IS NULL")->fetchColumn() == 0, 'legacy passwords left in place (cleanup deferred)');
$journal = $db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
check(in_array('migrate-accounts', $journal, true) && in_array(MIGRATION_CANONICAL, $journal, true), 'journal records the steps and canonical-v1');
$reference = structure($after['schema']); $referenceData = $after['data'];

// 6. Rerun: no effect.
[$code, $out] = $apply(); check($code === 0 && str_contains($out, 'Plan: 0 step(s)'), 'second apply has nothing to do');
check(structure(schemaSnapshot($db)) === $reference && dataFingerprints($db, schemaSnapshot($db)) == $referenceData &&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4, 'second apply changed nothing');

// 7. Behaviour of the migrated schema (each change rolled back).
$tx(fn() => $dateRules(2, 4, 'migrated database'));
$db->exec("INSERT INTO applications (person_id, advertisement_id, message) VALUES (2, 3, 'new')");
check((int)$db->query('SELECT COUNT(*) FROM applications WHERE created_at <=> application_date')->fetchColumn() === (int)$db->query('SELECT COUNT(*) FROM applications')->fetchColumn(), 'created_at equals application_date on every row, new ones included');
try { $db->exec("INSERT INTO applications (person_id, advertisement_id, created_at) VALUES (2, 4, NOW())"); check(false, 'created_at must not be writable'); } catch (PDOException) { check(true, 'created_at is a read-only alias'); }
$db->exec('DELETE FROM applications WHERE person_id=2 AND advertisement_id=3');
try { $db->exec("INSERT INTO applications (person_id, advertisement_id) VALUES (3, 1)"); check(false, 'duplicate application must fail'); } catch (PDOException $e) { check($e->getCode() === '23000', 'duplicate (person, advertisement) application rejected'); }
$tx(function () use ($db) {
    $db->exec("INSERT INTO people (firstname, lastname, email) VALUES ('Guest', 'One', 'guest-shared@example.invalid'), ('Guest', 'Two', 'guest-shared@example.invalid')");
    check(true, 'two guest profiles may share an e-mail');
    $db->exec("INSERT INTO users (firstname, lastname, email, password_hash) VALUES ('Guest', 'Account', 'guest-shared@example.invalid', '" . lockedPasswordHash() . "')");
    $uid = (int)$db->lastInsertId();
    $person = profileFor($db, ['id' => $uid, 'firstname' => 'Guest', 'lastname' => 'Account', 'email' => 'guest-shared@example.invalid', 'phone' => null]);
    check($person > 5 && $db->query("SELECT COUNT(*) FROM people WHERE email='guest-shared@example.invalid' AND user_id IS NULL")->fetchColumn() == 2, 'an account with a guest e-mail gets its own profile; guest profiles stay unlinked');
});
$tx(function () use ($db) {
    $db->exec("INSERT INTO advertisements (company_id, category_id, title, short_description, description, location, created_by) VALUES (1, 1, 'R1', 's', 'd', 'x', 1), (1, 1, 'R2', 's', 'd', 'x', 2)");
    check($db->query("SELECT COUNT(DISTINCT created_by) FROM advertisements WHERE title IN ('R1','R2')")->fetchColumn() == 2, 'two recruiters of the same company each create an advertisement');
});
$tx(function () use ($db) { try { $db->exec('DELETE FROM companies WHERE id=1'); check(false, 'company deletion must be refused'); } catch (PDOException $e) { check($e->errorInfo[1] === 1451, 'a company with advertisements cannot be deleted (RESTRICT)'); } });
$tx(function () use ($db) { $db->exec('DELETE FROM advertisements WHERE id=2'); check($db->query('SELECT COUNT(*) FROM applications WHERE advertisement_id=2')->fetchColumn() == 0, 'deleting an advertisement deletes its applications (CASCADE)'); });
$tx(function () use ($db) { $db->exec('DELETE FROM categories WHERE id=2'); check($db->query('SELECT category_id FROM advertisements WHERE id=2')->fetchColumn() === null, 'deleting a category keeps advertisements, category set to NULL'); });
$tx(function () use ($db) {
    $db->exec('DELETE FROM people WHERE id=2');
    check($db->query('SELECT COUNT(*) FROM company_members WHERE person_id=2')->fetchColumn() == 0 && $db->query('SELECT COUNT(*) FROM advertisements WHERE created_by IS NULL')->fetchColumn() == 3, 'deleting a profile removes its memberships, keeps its advertisements (author NULL)');
});
$tx(function () use ($db) {
    $uid = (int)$db->query('SELECT user_id FROM people WHERE id=3')->fetchColumn();
    $db->exec("INSERT INTO google_identities (google_sub, user_id) VALUES ('fixture-sub', $uid)");
    $db->exec("DELETE FROM users WHERE id=$uid");
    check($db->query('SELECT user_id FROM people WHERE id=3')->fetchColumn() === null && $db->query('SELECT COUNT(*) FROM applications WHERE person_id=3')->fetchColumn() == 2 && $db->query("SELECT COUNT(*) FROM google_identities WHERE google_sub='fixture-sub'")->fetchColumn() == 0, 'deleting an account keeps the profile and its applications; its Google identity goes');
});

// 8. Locked account: reset tool.
$uid4 = (int)$db->query('SELECT user_id FROM people WHERE id=4')->fetchColumn(); $newPw = 'Reset-' . bin2hex(random_bytes(8));
[$code, $out] = tool(['tools/reset-password.php', "--user-id=$uid4"], [], "$newPw\n");
check($code === 0 && !str_contains($out, $newPw) && password_verify($newPw, $hash(4)), 'locked account reset with a password read from stdin, never printed');
check(tool(['tools/reset-password.php', "--user-id=$uid4"], [], "$newPw\n")[0] === 1, 'reset refuses an account that is no longer locked without --force');
check(tool(['tools/setup.php'])[0] === 0, 'setup accepts the migrated (canonical) database without changes');

// 9. Interruption after each step, then resume to the same final state.
[$code, $out] = (function () use ($fresh, $migrate) { $fresh(); return $migrate(); })();
preg_match_all('/^\s+\d+ \[(?:ddl |data)\] ([a-z0-9_-]+) /m', $out, $m); $steps = $m[1];
check(count($steps) >= 20, 'plan lists the steps (' . count($steps) . ')');
foreach ($steps as $stepId) {
    $fresh();
    [$c1] = $apply(['MIGRATION_STOP_AFTER' => $stepId]);
    [$c2, $o2] = $apply();
    $s = state($db);
    check($c1 === 3 && $c2 === 0 && structure($s['schema']) === $reference && array_diff_key($s['data'], ['people' => 1]) == array_diff_key($referenceData, ['people' => 1]), "interrupted after $stepId, resumed to the same final state");
}
// Crash inside a multi-statement step: foreign key dropped but not re-added.
$fresh(); $apply(['MIGRATION_STOP_AFTER' => 'fk-advertisements-category_id']);
$db->exec('ALTER TABLE advertisements DROP FOREIGN KEY `advertisements_ibfk_3`');
[$code] = $apply(); check($code === 0 && structure(schemaSnapshot($db)) === $reference, 'crash between DROP and ADD of a foreign key is resumed');

// 10. Blockers: nothing is written.
$blocked = function (string $why, callable $mutate) use ($fresh, $db, $migrate, $apply) {
    $fresh(); $mutate($db); $s = state($db);
    [$c1, $o1] = $migrate(); [$c2] = $apply();
    check($c1 === 2 && $c2 === 2 && state($db) === $s && str_contains($o1, 'BLOCKED'), "blocked without change: $why");
};
$blocked('duplicate e-mail', fn($db) => $db->exec("UPDATE people SET email='historical-1@example.invalid ' WHERE id=2"));
$blocked('existing unlinked account', function ($db) { $db->exec("CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, firstname VARCHAR(100) NOT NULL, lastname VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL UNIQUE, phone VARCHAR(30) NULL, password_hash VARCHAR(255) NOT NULL, role ENUM('user','admin') NOT NULL DEFAULT 'user', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB"); $db->exec("INSERT INTO users (firstname, lastname, email, password_hash) VALUES ('X', 'Y', 'historical-3@example.invalid', '!x')"); });
$blocked('privileged legacy role', fn($db) => $db->exec("UPDATE people SET role='admin' WHERE id=1"));
$blocked('status outside the list', fn($db) => $db->exec("UPDATE applications SET status='archived' WHERE id=1"));
$blocked('orphan application', function ($db) { $db->exec('SET FOREIGN_KEY_CHECKS=0'); $db->exec("INSERT INTO applications (id, person_id, advertisement_id) VALUES (40, 99, 1)"); $db->exec('SET FOREIGN_KEY_CHECKS=1'); });
$blocked('unknown schema (missing table)', fn($db) => $db->exec('RENAME TABLE applications TO applications_old'));

// Runtime fixture for tests/migration/migration-http.cjs: a freshly migrated database with an admin.
$fx = $fresh(); [$code] = $apply(); check($code === 0, 'final migrated fixture for the HTTP test');
tool(['tools/create-admin.php', 'historical-3@example.invalid']);
file_put_contents($root . '/tests/.runtime-migration-fixtures.json', json_encode(['database' => $name, 'test_run_id' => testRunId(), 'passwords' => $fx['passwords'], 'emails' => array_map(fn($i) => "historical-$i@example.invalid", [1 => 1, 2 => 2, 3 => 3, 4 => 4]), 'admin_profile' => 3], JSON_THROW_ON_ERROR));

echo $failures ? "FAIL " . count($failures) . " of $checks migration DB checks\n" : "PASS $checks migration DB checks on $name\n";
exit($failures ? 1 : 0);
