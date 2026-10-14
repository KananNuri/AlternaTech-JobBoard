# Test isolation and audit fixes

Tests must never use the everyday development server or the real `alternatech` database. There are no fallback HTTP URLs. Ports 8080 and 8086 are refused. A different port alone is not proof of isolation.

## Disposable environment

Use a dedicated database user with privileges on the disposable database only. For migration tests, use MySQL 8 or MariaDB 10.6+ and a name ending in `_migration_test`. Never restore personal data into these fixtures.

In PowerShell, from the project directory, configure your **test** database connection and run identifier:

```powershell
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3306' # your disposable DB instance
$env:DB_NAME='alternatech_integration_test'
$env:DB_USER='alternatech_test' # privileges only on the test database
# Set DB_PASSWORD privately; do not put credentials in shell history or Git.
$env:TEST_RUN_ID=[guid]::NewGuid().ToString('N')
$env:INTEGRATION_TEST_DB='1'
$env:ISOLATED_TEST_SERVER='1'
$env:TEST_DB_NAME=$env:DB_NAME
$env:TEST_API_URL='http://127.0.0.1:8099'
php tools/setup.php --demo
php tests/integration-fixtures.php
php -S 127.0.0.1:8099 -t public tests/support/router.php
```

In a second terminal, set the same connection, URL, database and run identifier (do not generate a second identifier). Then:

```powershell
$fixtures=Get-Content tests/.runtime-fixtures.json -Raw | ConvertFrom-Json
$env:TEST_RECRUITER_EMAIL=$fixtures.recruiter.email
$env:TEST_RECRUITER_PASSWORD=$fixtures.password
node tests/ads-integrated-http.cjs
node tests/integration-http.cjs
```

The test router checks the actual connected database on **every request**, including static pages. The HTTP guard checks the server's database and run identifier before requests, and probes again before every modifying request. Redirects are never followed. The probe exists only in the test router, never in the normal application. Fixture files are tied to a database and run; stale fixtures are refused. Start a fresh disposable database for a new run and remove only its obsolete fixture JSON locally. Never commit fixture files.

`google-db.php` additionally requires `GOOGLE_TEST_DB=1`. `google-http.cjs` uses `GOOGLE_HTTP_TEST_URL`, subject to the same guard, and requires dummy OAuth configuration. Charles's 10-offer advertisement suite and Alice/Bob authorization suite require their own separate fixture databases as described in README; they now use the same isolation guard. Account/profile IDs in those legacy fixtures must not be copied from a real database.

For migration tests, use a fresh `alternatech_migration_test`, set `MIGRATION_TEST_DB=1` and `MIGRATION_TEST_DB_NAME=$env:DB_NAME` in addition to the shared settings. Run `php tests/migration/migration-db.php`, start the guarded server on that database, then run `node tests/migration/migration-http.cjs`.

## Checks without a database

```powershell
node tests/isolation-guard.cjs
node tests/catalogue-race.cjs
php tests/auth-rate-limit.php
php tests/ads-validation.php
php tests/google-auth.php
php tests/migration/schema-migration-unit.php
```

## Application changes

- Both router entry points use canonical authentication. The unsupported GET application alias returns 405. The authenticated `POST /api/applications/mine` compatibility alias delegates to canonical Apply; Charles's existing test contract is preserved.
- Login limits survive cookie replacement: at most 10 attempts per account and 60 per source IP per minute, using locked shared storage outside public/. Forwarded headers are not trusted. Multi-host deployments need a shared proxy/Redis limiter. Storage failure refuses login rather than bypassing the limit.
- A guest cannot repeat an application to the same ad within the same session. Distinct sessions and accounts are not linked or blocked solely by an unverified email. Recruiter self-applications remain allowed, preserving the existing rule; changing that requires a product decision.
- Catalogue requests cancel older searches. Apply forms open only after successful detail retrieval. PUT clears omitted optional contract types. Search wildcard characters are treated literally. Messages exceeding 60,000 UTF-8 bytes are rejected before SQL.
- Profile updates avoid repeated AUTO_INCREMENT consumption. Registration reports an existing email clearly; expired sign-out reloads the page.
- France Travail employers are never looked up by name. An existing offer reuses its employer through `(source, external_id)`; a new offer gets a separate employer record. This intentionally permits duplicate employer names and avoids linking imported offers to local recruiters. Existing unsafe associations stop the import for manual reconciliation. A database lock serializes import runs. A future provider employer identifier can consolidate records once its identity rules are established.

## Separate operational actions

These source changes do not alter a real database, choose an administrator, reset someone else's password, or rotate external credentials.

1. Choose an existing account and run `php tools/create-admin.php <email>` with the intended connection.
2. Reset locked accounts privately with `tools/reset-password.php`. Validate account access after migration.
3. Run `php tools/clear-legacy-passwords.php` for a count-only dry run. After validating migration/account access and backing up, use `--apply --database=<exact-name> --confirm-backup`. It clears only legacy values, refuses unmigrated/locked accounts, and does not drop columns. Existing backups still contain old values; restrict access and manage retention.
4. Rotate the credential reported in the historical Node branch through its owner. Removing it from current files does not revoke it.
5. Conversion to UNSIGNED and dropping historical columns remain separate schema migrations, not prerequisites for these fixes.

## Verification on this branch

48 PHP files and 17 JavaScript files passed syntax checks. Database-free isolation, catalogue race, login limiter, advertisement validation, Google claims and 105 schema-migration unit checks passed. A new synthetic database on a separate local MariaDB 10.4 instance passed 23 advertisement HTTP checks, 130 integration HTTP checks, Google database checks, legacy cleanup checks and imported-employer checks. That older local instance is supplementary evidence, not certification of historical migration on the documented MySQL 8 / MariaDB 10.6+ requirement. Full historical migration and real provider consent/exchange were not rerun. The real alternatech database was not accessed.

The database tests `tests/maintenance-db.php` and `tests/import-employers-db.php` also use INTEGRATION_TEST_DB, DB_NAME and TEST_RUN_ID. Run them only on the synthetic canonical fixture database, after preparing its accounts. Maintenance tests refuse schemas that already have a historical password column.
