<?php
declare(strict_types=1);
// Synthetic fixtures for the database-level migration tests. They reproduce the STRUCTURE of the historical
// database (Charles's schema) with entirely fictional rows: example.invalid e-mails, bcrypt hashes generated at
// run time for random passwords, and one random unrecognised value. No real data is read or copied.
require_once dirname(__DIR__, 2).'/api/config/database.php';
require_once dirname(__DIR__, 2).'/tools/migration-lib.php';

/** Refuses anything but an explicitly enabled, dedicated *_migration_test database. */
function migrationTestGuard(): PDO {
    $name = envValue('DB_NAME', '');
    if (PHP_SAPI !== 'cli' || getenv('MIGRATION_TEST_DB') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_migration_test$/D', $name)) {
        fwrite(STDERR, "Refused: set MIGRATION_TEST_DB=1 and DB_NAME=<name ending with _migration_test> (a disposable database).\n"); exit(2);
    }
    require_once dirname(__DIR__).'/support/database-guard.php';
    $db = testDatabaseGuard('MIGRATION_TEST_DB');
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $name) { fwrite(STDERR, "Refused: connected database differs from DB_NAME.\n"); exit(2); }
    return $db;
}

/** Empties the disposable test database between scenarios (guarded: only *_migration_test). */
function resetTestDatabase(PDO $db): void {
    if (!preg_match('/_migration_test$/D', (string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('reset refused outside a *_migration_test database');
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) $db->exec('DROP TABLE ' . quoteIdentifier($t));
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** Historical DDL (structure only), identical to the historical database's CREATE TABLE statements. */
const HISTORICAL_DDL = <<<'SQL'
CREATE TABLE `people` (
  `id` int NOT NULL AUTO_INCREMENT, `first_name` varchar(100) NOT NULL, `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL, `phone` varchar(30) DEFAULT NULL, `password` varchar(255) NOT NULL, `role` varchar(20) NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`), UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `companies` (
  `id` int NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `advertisements` (
  `id` int NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `short_description` varchar(500) NOT NULL, `description` text NOT NULL,
  `salary` varchar(100) DEFAULT NULL, `location` varchar(255) NOT NULL, `working_time` varchar(100) NOT NULL,
  `company_id` int NOT NULL, `category_id` int NOT NULL, `created_by` int NOT NULL,
  PRIMARY KEY (`id`), KEY `company_id` (`company_id`), KEY `category_id` (`category_id`), KEY `created_by` (`created_by`),
  CONSTRAINT `advertisements_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `advertisements_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `advertisements_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `applications` (
  `id` int NOT NULL AUTO_INCREMENT, `person_id` int NOT NULL, `advertisement_id` int NOT NULL, `message` text,
  `application_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, `status` varchar(50) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_applications_person_ad` (`person_id`,`advertisement_id`), KEY `advertisement_id` (`advertisement_id`),
  CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`advertisement_id`) REFERENCES `advertisements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `company_members` (
  `id` int NOT NULL AUTO_INCREMENT, `person_id` int NOT NULL, `company_id` int NOT NULL, `role` varchar(50) NOT NULL,
  PRIMARY KEY (`id`), KEY `person_id` (`person_id`), KEY `company_id` (`company_id`),
  CONSTRAINT `company_members_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `company_members_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL;

/**
 * Builds the historical structure with synthetic rows mirroring the real counts and id patterns:
 * people 1-4 (1-2 recruiters of company 1, 1-3 bcrypt $2y$/$2b$/$2y$, 4 an unrecognised value),
 * companies 2 and 1, categories 1-4, advertisements 1-10, applications 1, 2, 3 and 5 (id 4 missing).
 * Returns the synthetic passwords (memory only) and expected ids.
 */
function buildHistoricalFixture(PDO $db): array {
    foreach (array_filter(array_map('trim', explode(';', HISTORICAL_DDL))) as $statement) $db->exec($statement);
    $passwords = []; for ($i = 1; $i <= 3; $i++) $passwords[$i] = 'Synthetic-' . bin2hex(random_bytes(8));
    $y12 = fn(string $p) => password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
    $b10 = '$2b$' . substr(password_hash($passwords[2], PASSWORD_BCRYPT, ['cost' => 10]), 4); // same algorithm, $2b$ prefix
    $unknown = substr(bin2hex(random_bytes(8)), 0, 11);                                       // 11 characters, no "$"
    $people = $db->prepare('INSERT INTO people (id, first_name, last_name, email, phone, password, role) VALUES (?, ?, ?, ?, NULL, ?, ?)');
    foreach ([[1, 'Alice', 'Fixture', $y12($passwords[1])], [2, 'Bruno', 'Fixture', $b10], [3, 'Chloe', 'Fixture', $y12($passwords[3])], [4, 'Dimitri', 'Fixture', $unknown]] as [$id, $f, $l, $pw])
        $people->execute([$id, $f, $l, "historical-$id@example.invalid", $pw, 'user']);
    $db->exec("INSERT INTO companies (id, name) VALUES (2, 'Fixture Company B'), (1, 'Fixture Company A')");
    $db->exec("INSERT INTO categories (id, name) VALUES (2, 'Fixture Data'), (1, 'Fixture Development'), (3, 'Fixture AI'), (4, 'Fixture Cloud')");
    $ad = $db->prepare("INSERT INTO advertisements (id, title, short_description, description, salary, location, working_time, company_id, category_id, created_by) VALUES (?, ?, 'Fixture summary', ?, 'Fixture salary', 'Fixture City', '35 hours/week', 1, ?, ?)");
    foreach ([1 => [1, 1], 2 => [2, 2], 3 => [1, 1], 4 => [1, 1], 5 => [1, 1], 6 => [1, 2], 7 => [1, 1], 8 => [1, 2], 9 => [1, 1], 10 => [1, 1]] as $id => [$cat, $by])
        $ad->execute([$id, "Fixture offer $id", "Fixture description $id\r\nSecond line", $cat, $by]);
    $app = $db->prepare('INSERT INTO applications (id, person_id, advertisement_id, message, application_date, status) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([[1, 3, 1, '2026-10-07 10:27:24', 'pending'], [2, 4, 2, '2026-10-07 10:27:24', 'pending'], [3, 1, 1, '2026-10-09 16:47:47', 'reviewed'], [5, 3, 2, '2026-10-12 12:08:59', 'pending']] as [$id, $p, $a, $d, $s])
        $app->execute([$id, $p, $a, "Fixture message $id", $d, $s]);
    $db->exec("INSERT INTO company_members (id, person_id, company_id, role) VALUES (1, 1, 1, 'recruiter'), (2, 2, 1, 'recruiter')");
    foreach (['advertisements' => 52, 'applications' => 54, 'categories' => 52, 'companies' => 52, 'company_members' => 52, 'people' => 5] as $t => $n) $db->exec("ALTER TABLE $t AUTO_INCREMENT=$n");
    return ['passwords' => $passwords, 'locked_profile' => 4, 'application_ids' => [1, 2, 3, 5], 'recruiters' => [1, 2]];
}
