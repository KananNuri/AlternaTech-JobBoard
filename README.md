# AlternaTech Job Board

A PHP/PDO/MySQL job board by Kanan and Charles for technology opportunities in France.

## Install and run

Requirements: PHP 8.4+, PDO with `pdo_mysql`, and MariaDB 10.6+ or MySQL 8. Enable `curl` for optional Google/France Travail integrations and `mbstring` for the importer. No Node packages are needed; Node 20+ is used only for HTTP tests.

1. Copy `.env.example` to `.env` and set the five `DB_*` variables. Keep credentials private.
2. With a setup DB user allowed to CREATE/ALTER tables, initialize a fresh demo database through the PHP backend:

   ```powershell
   php tools/setup.php --demo
   ```

   This creates the schema, migrates it and inserts 20 fictional offers. `--demo` refuses to overwrite populated companies/categories/advertisements. For an existing installation, back up the database and run `php tools/setup.php` without `--demo`. Migration never deletes/merges records; existing duplicate profiles/applications stop it for manual review.
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

- `/`: paginated board, keyword/location/category filters, detail fetch without reload/popup, and Apply on every card. Guests provide details in an inline form; signed-in users reuse their profile.
- `/login.php`: email/password and optional Google sign-in. Admins go to administration; others return to the board.
- `/account.php`: create/edit first/last name, email, phone and password. Email/password changes require the current local password. Google-only accounts can edit name/phone; verified account linking/password recovery is a future feature.
- `/admin.php`: server-protected administration with paginated CRUD for all eight tables, relationship checks and inline deletion confirmation. Active admins cannot delete/demote themselves. Manage linked profiles through their user account.
- Charles's existing recruiter panel remains on the board. integration.js supplies its missing Edit callback; recruiters can also see candidate contacts/messages and change statuses within their companies.

Applications/messages are saved in SQL and visible to authorized recruiters/admins. No SMTP service is configured; the app does not claim to send email.

## Database and sessions

`users` stores canonical accounts, password hashes and user/admin roles. `people` stores applicant/recruiter profiles, with nullable `user_id` for guests and one linked profile per account. Other tables: `companies`, `categories`, `advertisements`, `applications`, `company_members`, `google_identities`.

Account and profile IDs differ. The integrated session stores `account_id = users.id` and legacy `user_id = people.id` to preserve Charles's handlers. Memberships and ad creators reference profile IDs. Assign recruiter company memberships in the admin panel. `application_date` is a generated alias for `created_at`. A unique `(person_id, advertisement_id)` prevents duplicate authenticated applications. Guest profiles are separate and never automatically linked by email.

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

```powershell
Get-ChildItem api,public,tools,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
Get-ChildItem public/assets -Filter *.js | ForEach-Object { node --check $_.FullName }
php tests/ads-validation.php
php tests/google-auth.php
```

Use **only an isolated disposable DB/server** with 20 demo offers for integration tests:

```powershell
$env:INTEGRATION_TEST_DB='1'
$env:TEST_API_URL='http://127.0.0.1:8080'
php tests/integration-fixtures.php
$fixtures = Get-Content tests/.runtime-fixtures.json -Raw | ConvertFrom-Json
$env:TEST_RECRUITER_EMAIL=$fixtures.recruiter.email
$env:TEST_RECRUITER_PASSWORD=$fixtures.password
node tests/ads-integrated-http.cjs
node tests/integration-http.cjs
```

The gitignored fixture file contains random test credentials and only example.invalid accounts. Fixture reruns clean their preceding prefix. The integration suite cleans generated records and retains a test admin until fixture rerun. For `ads-integrated-http.cjs` (the standard 20-offer demo), set `TEST_RECRUITER_EMAIL`/`TEST_RECRUITER_PASSWORD` for a disposable company-1 recruiter; run before integration cleans it. Charles's unchanged `ads-http.cjs` expects exactly 10 local seed offers, so run it against a separate disposable 10-offer fixture rather than modifying the test or standard demo. `google-db.php` requires `GOOGLE_TEST_DB=1`; `google-http.cjs` requires curl and a dummy local OAuth configuration. Set `GOOGLE_HTTP_TEST_URL` for a custom server port; it never follows the provider redirect.

Charles's unchanged `applications-http.cjs` expects canonical Alice/Bob IDs 1/3, company TechNova and application 3. For this test, initialize a separate fresh disposable database named `alternatech_authorization_test`, set `INTEGRATION_TEST_DB=1` and a test-only `TEST_FIXTURE_PASSWORD`, then run `php tests/charles-authorization-fixtures.php`. Run a separate local server against that DB. Set `TEST_RECRUITER_EMAIL=alice@example.invalid`, `TEST_USER_EMAIL=bob@example.invalid`, both corresponding password variables to `TEST_FIXTURE_PASSWORD`, and `TEST_API_URL` to that server before running the original test. These synthetic fixtures never overwrite an existing account database.

[Requirement audit](docs/integration-audit.md).
