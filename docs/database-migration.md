# Database migration — canonical schema v1

`database/schema.sql` creates the canonical schema in an **empty** database (through `tools/setup.php`).
`tools/migrate.php` brings an **existing** database to the same contract. Both share the checks of
`tools/migration-lib.php`. Nothing in this procedure deletes a row, a table or a column.

## Canonical contract

| Table | Contract |
|---|---|
| `users` | Accounts: `firstname`, `lastname`, `email` (unique sign-in identifier), `phone`, `password_hash`, `role` (`user`/`admin`), `created_at`, `updated_at` |
| `people` | Profiles: `user_id` (unique, nullable, `ON DELETE SET NULL`), `firstname`, `lastname`, `email` (**not unique**, plain index), `phone`, `created_at`. Guests have `user_id = NULL`; a profile is never linked to an account by e-mail |
| `companies` | `name` (**not unique**: homonyms allowed; plain index used by the import), `description`, `email`, `phone`, `website`, `created_at` |
| `categories` | `name` unique |
| `advertisements` | `company_id` (**RESTRICT**: a company with offers cannot be deleted), `category_id` (nullable, `SET NULL`), `created_by` (profile, nullable, `SET NULL`), `contract_type`, `source`, `external_id`, `source_url` (unique `(source, external_id)`), `working_time` nullable, `created_at`, `updated_at` |
| `applications` | `application_date DATETIME NULL DEFAULT CURRENT_TIMESTAMP` (stored) with `CHECK (application_date IS NOT NULL)`, and `created_at` (generated alias), `status` ENUM(pending, reviewed, accepted, rejected), unique `(person_id, advertisement_id)`; `advertisement_id` and `person_id` `ON DELETE CASCADE` |
| `company_members` | `(company_id, person_id)` unique, `role` = `recruiter`, both keys `ON DELETE CASCADE`; several recruiters per company |
| `google_identities` | `google_sub` primary key, `user_id` unique (`ON DELETE CASCADE`); created only by a Google sign-in for a new e-mail |
| `schema_migrations` | Journal of applied steps; `canonical-v1` once the contract is reached |

**Tolerated variants** (accepted as canonical, so no data has to move): signed `INT` identifiers on migrated
databases; the historical `company_members.id` column; legacy `people.password` / `people.role`, nullable;
wider historical lengths (title, location, e-mail 255); `created_at TIMESTAMP` stored with a generated
`application_date` (databases built from the previous `main` schema).

**Why the stored date is declared `NULL`:** MySQL computes a generated column before applying the
`DEFAULT CURRENT_TIMESTAMP` of a `NOT NULL` column it depends on, so with `NOT NULL` every `INSERT` that
omits the date fails (error 1292 under strict mode). The stored date is therefore nullable, and for
`application_date` an enforced `CHECK (application_date IS NOT NULL)` (`chk_application_date`) still rejects an
explicit `NULL` on `INSERT` or `UPDATE` (error 3819). The step `applications-date-not-null-check` applies this
before the alias is added (existing dates are kept; it is blocked if a row has no date) and also repairs a
database installed from the earlier, defective `schema.sql`.

### Deletion matrix

| Deleted | Effect |
|---|---|
| Advertisement | Its applications are deleted (the recruiter UI says so and asks for confirmation) |
| Company | **Refused** while it has advertisements; its memberships are deleted otherwise |
| Category | Advertisements kept, `category_id` set to NULL |
| Account (`users`) | Profile and applications kept (`people.user_id` set to NULL); Google identity deleted |
| Profile (`people`, unlinked only through the admin) | Its applications and memberships deleted; its advertisements kept with `created_by` NULL |

## What `tools/migrate.php` does

1. Reads `information_schema` and recognises the shape: `historical` (Charles's schema: credentials in `people`,
   no `users`), `partial` (an interrupted migration), `upgradable` (Kanan's or the previous `main` schema),
   `canonical`, `empty` (use `setup.php`) or `unknown` (**refused**: missing core table, non-integer id,
   mixed name columns, two real date columns, foreign key type mismatch…).
2. Runs every data check before any write: orphans for each foreign key, duplicated application/membership/
   import pairs, statuses outside the list, and the account checks below. Any finding stops the run.
3. Prints the plan (DDL steps, the data step, deferred cleanups). **Default is a dry run: nothing is written.**
4. With `--apply --database=<name>` (and `--confirm-backup` unless the name ends with `_test`), it executes the
   first step, re-reads the schema, recomputes the plan and repeats. Each step is recorded in
   `schema_migrations`. An interrupted run resumes where it stopped; a second run does nothing.
5. At the end it verifies the contract and compares, for every historical table, the row count, the id list,
   a hash of the row contents and `AUTO_INCREMENT` with the values read before the run.

Output contains profile ids and counts only: never e-mails, names, phones, passwords or hashes.

### Account migration (the only data step, one transaction)

For each profile holding a legacy `password` and no account: one `users` row (`role = user`, same names,
e-mail and phone), then `people.user_id` is set. Profile ids never change and `users.id` is a new, unrelated id.

- A value is copied to `users.password_hash` only if it is a well-formed bcrypt hash (`$2y$`, `$2b$`, `$2a$`,
  cost 04–31), which `password_verify()` accepts; `$2b$`/`$2a$` hashes are rehashed by the next login.
- Any other value is **not imported**. The account receives `!locked:` plus random characters: not a hash, so
  no password ever matches, and the `!` lets an administrator find accounts to reset.
- The run stops (nothing written) if an e-mail is malformed, padded, duplicated, or already used by an account,
  or if a legacy role is not `user`. No account is merged or linked by e-mail; no role is granted.

Reset a locked account (the password is read from standard input, never printed):

```powershell
php tools/reset-password.php --user-id=<users.id>
```

Then give the password to its owner through a separate, trusted channel. No administrator is created by the
migration: run `php tools/create-admin.php <existing-account-email>` for the account you choose.

### Deferred cleanups (reported, never executed by this version)

- `clear-legacy-passwords`, then `drop-legacy-people-columns`: only after the accounts are validated; start with
  the locked accounts, whose legacy value may be a plaintext password.
- `convert-ids-unsigned`: drop/modify/recreate every foreign key; do it separately, application stopped.

## Procedure for a real database

1. **Rotate exposed credentials** first (a MySQL `root` password was committed on a non-merged branch; do not
   rewrite history, change the password and any reuse of it).
2. **Stop every server writing to the database**, or switch to maintenance.
3. **Fresh backup**: `mysqldump --single-transaction --routines --triggers <db> > <outside the repository>`.
   Restore it into a temporary database and compare row counts and ids with the source.
4. Keep dumps outside the repository with restricted access (`/*.sql` at the root is ignored by Git).
5. With setup credentials (CREATE/ALTER/INDEX/REFERENCES on that database):
   `php tools/migrate.php` — read the dry-run plan; it must match the one validated on a copy.
6. `php tools/migrate.php --apply --database=<db> --confirm-backup`.
7. Check the final report ("counts, ids, row contents and AUTO_INCREMENT … unchanged"), run the test suites
   against a server on that database, reset locked accounts, create the administrator.

### Rollback

- Blocked or refused run: nothing was written.
- Failure during the DDL steps: the journal and the dry run show what is done; fix the cause and rerun (resume),
  or restore the backup.
- After a completed migration: restore the backup into a **new** database (for example `alternatech_restore`)
  and point `DB_NAME` to it — no table is dropped. Writes made after the migration are lost.
- The previous `main` code never worked on the historical database, so there is no earlier working
  "code + historical database" state to return to.

## Tests

| Test | Database | Command |
|---|---|---|
| Unit: shapes, complete and idempotent plans, refusals, account checks, password formats, locked values | none | `php tests/migration/schema-migration-unit.php` |
| Fresh install, refused setup, dry run without writes, apply guards, ids/contents/AUTO_INCREMENT kept, accounts, rerun, dates, guests, duplicates, two recruiters, deletion matrix, reset tool, interruption after each step and inside a step, blockers | disposable `*_migration_test` (emptied between scenarios) | `MIGRATION_TEST_DB=1 DB_NAME=<x>_migration_test php tests/migration/migration-db.php` |
| HTTP on the migrated fixture: logins, locked account, two recruiters, 409 duplicate, guests, no e-mail link, admin without credentials, company deletion refused | the same, served by a dedicated server | `TEST_API_URL=… MIGRATION_TEST_DB_NAME=… node tests/migration/migration-http.cjs` |

The fixtures reproduce the historical structure with fictional rows only (`example.invalid`, hashes generated
at run time). The real historical passwords are unknown, so real logins cannot be tested; Google OAuth needs
real credentials and is covered by the synthetic `tests/google-db.php` only.
