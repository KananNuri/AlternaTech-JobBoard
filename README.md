# AlternaTech Job Board

A PHP/PDO/MySQL job board by Kanan and Charles for technology opportunities in France.

## Install and run

Requirements: PHP 8.4+ with the `dom` extension (its HTML5 parser renders the translated board), PDO with `pdo_mysql`, and MariaDB 10.6+ or MySQL 8. Enable `curl` for optional Google/France Travail integrations and `mbstring` for the importer. No Node packages are needed; Node 20+ is used only for HTTP tests.

1. Copy `.env.example` to `.env` and set the five `DB_*` variables. Keep credentials private.
2. With a setup DB user allowed to CREATE/ALTER tables, initialize a fresh demo database through the PHP backend:

   ```powershell
   php tools/setup.php --demo
   ```

   This installs the canonical schema into an **empty** database and inserts 20 fictional offers. `--demo` refuses to overwrite populated companies/categories/advertisements. `setup.php` never alters an existing database: one that already has tables is accepted only if it follows the canonical schema, otherwise setup stops before running any statement.

   **Existing database** (historical, partially migrated or older schema): back it up, then use `tools/migrate.php`, which is a read-only dry run by default (`php tools/migrate.php`) and only writes with `--apply --database=<name>` (plus `--confirm-backup` for a non-test database). It refuses unknown schemas and ambiguous data, keeps every historical id and relation, migrates legacy bcrypt accounts, locks accounts whose legacy password has an unrecognised format, never deletes data and can be rerun or resumed. Read [the migration guide](docs/database-migration.md) first.
3. Use a separate runtime DB user with SELECT/INSERT/UPDATE/DELETE privileges. Start from the repository root:

   ```powershell
   php -S 127.0.0.1:8080 -t public api/integration-router.php
   ```

4. Open [the local job board](http://127.0.0.1:8080), create an account at `/account.php`, then provision the first admin using setup credentials:

   ```powershell
   php tools/create-admin.php your-existing-account@example.com
   ```

   There is no default admin/password. Public registration cannot choose a privileged role. Sign in again; admins are redirected to `/admin.php`.

**Use `api/integration-router.php`, not the earlier router, for the complete app.** It reuses Charles's recruiter/advertisement/application handlers unchanged, supplies canonical auth, and routes missing features. Serve only `public/`, never the repository root. PHP's built-in server is for development; deployment must keep that document root, route APIs through the integration entry point, and use HTTPS.

## Pages

- `/`: paginated board with keyword/location/category filters. On wide screens the filters, list and pagination fill the left column and the selected offer's details the right one (fetched without reload/popup, showing only values the API returns); below 900 px the details follow the list with a "Back to results" button. Apply on every card: guests provide details in an inline form; signed-in users reuse their profile. Email and Google sign-in sit in the collapsible account menu of the header. Signed-in users get a "My space" section with their applications; recruiters also see received applications and their recruiter area there.
- `/login.php`: email/password and optional Google sign-in. Admins go to administration; others return to the board.
- The header navigation follows the session (visitor, signed-in user, administrator). It only reflects state: pages and APIs keep enforcing access on the server.
- `/account.php`: create/edit first/last name, email, phone and password. Email/password changes require the current local password. Google-only accounts can edit name/phone; verified account linking/password recovery is a future feature.
- `/admin.php`: server-protected administration with paginated CRUD for all eight tables, relationship checks and inline deletion confirmation. Active admins cannot delete/demote themselves. Manage linked profiles through their user account.
- Charles's existing recruiter panel remains on the board. integration.js supplies its missing Edit callback and lets recruiters publish, fully edit and delete advertisements for their assigned companies (deletion is confirmed inline and also removes the advertisement's applications); validation errors are shown next to the affected fields, while authorization, session and other errors are shown in the form's message area. Recruiters can also see candidate contacts/messages and change statuses within their companies.

## Interface languages

The interface is available in French (default) and English, switched with the FR/EN control in the header. Interface text lives in one dictionary, `lang/messages.json`, used both by PHP rendering (`public/i18n.php`) and by `public/assets/i18n.js`, so every page is rendered in the chosen language and switching re-translates the page in place (open forms and the selected offer are kept). The choice is stored in the `alternatech_lang` preference cookie (`fr` or `en`, one year), separate from the session cookie. Offer titles, descriptions, company names and other API data are shown as returned and never translated; known English backend error messages are translated in the browser and unknown ones are shown as-is. Open the board through `/` (public/index.php): `public/index.html` is its template and has no dictionary when opened directly. To add a text, add the same key to both languages in `lang/messages.json`.

Applications/messages are saved in SQL and visible to authorized recruiters/admins. No SMTP service is configured; the app does not claim to send email.

## Database and sessions

`users` stores canonical accounts, password hashes and user/admin roles. `people` stores applicant/recruiter profiles, with nullable `user_id` for guests and one linked profile per account. Other tables: `companies`, `categories`, `advertisements`, `applications`, `company_members`, `google_identities`.

Account and profile IDs differ. The integrated session stores `account_id = users.id` and legacy `user_id = people.id` to preserve Charles's handlers. Memberships and ad creators reference profile IDs. Assign recruiter company memberships in the admin panel. `applications.application_date` (DATETIME) is the stored date and `created_at` its generated alias; databases built from the previous schema keep the reverse alias, which is equivalent. A unique `(person_id, advertisement_id)` prevents duplicate authenticated applications. `people.email` is not unique: guest profiles are separate and never automatically linked by email; `users.email` is unique. Company names are not unique. A company that still has advertisements cannot be deleted; deleting an advertisement deletes its applications. See [the migration guide](docs/database-migration.md) for the full contract and deletion matrix.

## API

Write bodies use JSON. Authenticated writes and guest Apply require `X-CSRF-Token` from the session. Get a guest token from `GET /api/auth/csrf`. Admin roles are checked in SQL on each request. Creation returns 201, deletion 204, validation/auth/relationship failures return appropriate JSON 4xx responses.

| Routes | Methods | Access |
|---|---|---|
| `/api/auth/register`, `/api/auth/login` | POST | Public |
| `/api/auth/session`, `/api/auth/csrf` | GET | Public |
| `/api/auth/logout` | POST | Signed in + CSRF |
| `/api/account` | GET, PATCH | Signed in; write + CSRF |
| `/api/auth/google/{start,callback,session}` | GET | Optional provider/shared session |
| `/api/auth/google/logout` | POST | Shared session + CSRF |
| `/api/catalog/ads` | GET | Public, paginated/filterable |
| `/api/categories`, `/api/companies/{id}/ads` | GET | Public; company ads paginated |
| `/api/ads`, `/api/ads/{id}` | GET | Original public array/detail contract |
| `/api/ads` | POST | Company recruiter + CSRF |
| `/api/ads/{id}` | PUT, PATCH, DELETE | Company recruiter + CSRF |
| `/api/ads/{id}/applications` | POST | Guest/signed-in profile + CSRF |
| `/api/applications/mine` | GET | Signed in |
| `/api/companies/mine`, `/api/companies/mine/ads` | GET | Only assigned companies |
| `/api/companies/mine/applications` | GET, PATCH | Assigned recruiter; write + CSRF |
| `/api/recruiter/applications` | GET | Assigned recruiter, paginated contacts |
| `/api/admin` | GET | Admin form metadata |
| `/api/admin/{table}`, `/api/admin/{table}/{key}` | GET, POST / GET, PUT, PATCH, DELETE | Admin; writes + CSRF |

Pagination: `page` (1+) and `per_page` (1–100). Catalog filters: `q`, `location`, `category_id`. Catalog/admin results contain `items`, `total`, `page`, `pages`. Membership keys: `company_id:person_id`; Google keys: URL-encoded subjects. Admin user JSON never includes password hashes.

Recruiter ad creation/replacement retains Charles's required title, summary, description, location, working time, company and category validation. Imported nullable categories remain visible through LEFT JOIN. Admin CRUD supports nullable schema fields independently.

## Optional providers

[Google setup](docs/google-login.md). Real Google consent/exchange needs your OAuth credentials. Tests use dummy redirect configuration and synthetic claims without claiming real provider authentication.

France Travail: set its environment variables, enable curl/mbstring, and run `php api/integrations/france-travail/sync.php informatique`. The importer fetches one bounded batch of up to 50 offers; repeated `(source, external_id)` imports update existing records. Provider pagination/scheduling are optional bonuses outside the supplied mandatory specification.

## Tests

See [test isolation and audit fixes](docs/test-isolation.md) for the complete guarded setup. Test databases, run identifiers and HTTP URLs must be explicit. Never use the development server on 8080/8086. The dedicated server must use `tests/support/router.php`, not the normal router.

Checks without a database:

```powershell
node tests/isolation-guard.cjs
node tests/catalogue-race.cjs
php tests/auth-rate-limit.php
php tests/ads-validation.php
php tests/google-auth.php
php tests/migration/schema-migration-unit.php
```

Integration suites use synthetic disposable data only. `ads-integrated-http.cjs` expects 20 demo offers and a company-1 test recruiter. Run it before `integration-http.cjs`, which cleans its fixture accounts. The original `ads-http.cjs` expects a separate 10-offer fixture. `applications-http.cjs` uses a separate fresh `alternatech_authorization_test`: set `INTEGRATION_TEST_DB=1`, `TEST_RUN_ID` and a private test-only `TEST_FIXTURE_PASSWORD`, run `tests/charles-authorization-fixtures.php`, then provide Alice/Bob test credentials and the guarded server URL to the HTTP test. `google-db.php` requires `GOOGLE_TEST_DB=1`; Google HTTP tests require dummy OAuth settings. Migration suites require a dedicated `*_migration_test` database. All of these suites now enforce the shared guard.

[Requirement audit](docs/integration-audit.md) records historical verification; [current fixes and remaining operational actions](docs/test-isolation.md) describe this branch.
