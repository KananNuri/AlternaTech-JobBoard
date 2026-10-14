# Integration audit — 12 October 2026

Final baseline: Charles's main 70be160 (including the c4d4994 recruiter dashboard, c6abff5 ad/test update and latest application authorization tests). Both concurrent updates were incorporated without overwriting his files. It includes Kanan's 91fd292 history. The supplied ZIP contains a bootstrap exercise and Job Board project specification v4.0.0, not source code. Documents were treated as requirements, not authorization for unrelated actions.

| Requirement | Implementation |
|---|---|
| SQL ads, companies, people and application history | Canonical schema, FK relationships and messages/dates |
| Populate SQL through backend | tools/setup.php --demo; refuses populated-table overwrite |
| Dynamic board/details/wages/location/working time | Existing board retained, paginated search added, no detail popup/reload |
| Apply on every card and inline personal-info/message form | Guest profiles and saved authenticated profiles; transactional inserts |
| Sign-in, registration and account modification | Dedicated login/account pages; canonical users/password_hash |
| Admin redirect/admin-only page | Login redirect plus server-side page and API role gates |
| CRUD and pagination | Eight-table administration with validation, FK conflicts and hidden password hashes |
| Bootstrap company-ad/detail/create/apply routes | Company catalog, existing recruiter/detail APIs and URL-bound Apply |
| Preserve Charles | His backend handlers, router and tests are unchanged; the 13 October frontend redesign modified three of his presentation files (see below) |
| Local testing and install instructions | Syntax/unit/SQL/HTTP tests, fresh/upgrade setup and browser verification |

## Resolved integration defects

1. Password handlers queried credential/name columns missing from people. Integrated auth uses canonical users/password_hash while preserving the original files.
2. Missing company_members/created_by are added with profile FKs.
3. Generated application_date aliases created_at for Charles's unchanged queries.
4. Explicit account_id/profile-ID session bridge prevents account/person ID confusion.
5. Google and password share authentication/profile and can Apply.
6. Unique person/ad SQL key prevents duplicate authenticated applications.
7. integration.js supplies Charles's undefined Edit callback and fetches full ad details before editing to retain contract data.
8. The additive ads-integrated-http.cjs test uses sessions/CSRF and retained Charles recruiter validation. Charles's concurrently updated tests remain unchanged, and nullable imported categories remain supported in schema/read/admin paths.

## Architecture

Use api/integration-router.php. It delegates Charles's recruiter/advertisement/application handlers, supplies canonical auth and routes new account/catalog/guest-apply/admin APIs. public/index.php serves public/index.html with the integration scripts, the session-aware header and server-side translation; jobboard.css holds the design tokens and board layout, integration.css the shared components. Credentials are not duplicated into people.

Migration is additive/idempotent and refuses duplicate profiles/applications rather than deleting history. MySQL/MariaDB DDL is not transactional: back up first and rerun after resolving a conflict. Legacy sessions are invalidated. CLI provisions admin; public signup cannot choose privileged roles. (Superseded on 14 October by the schema reconciliation below: the earlier migrate.php only handled Kanan's schema.)

## Verification

PHP 8.4.25 with an isolated MariaDB 11.4.5 server on localhost:

- Upgraded original six-table schema/20 offers; repeated migration retained records.
- Fresh eight-table setup/demo populated through backend; repeat setup succeeded, repeat demo refused overwrite.
- Advertisement validation, Google claim/state/config and identity DB tests.
- Existing Charles application and Google HTTP checks.
- Full HTTP account/guest/recruiter/admin suite: deliberately different account/person IDs; shared auth; duplicate/CSRF/other-company rejection; eight-table CRUD; bounded pagination; inaccessible private files.
- Browser: guest Apply saved, admin login redirected, admin company creation/editing persisted, Charles's recruiter Edit button saved/restored an advertisement with its contract retained, and shared logout returned to the guest board. Browser error/warning logs were empty for admin and recruiter checks.
- Final counts: 127 integration HTTP checks, 23 integrated advertisement HTTP checks, 25 unchanged Charles advertisement HTTP checks, 11 original application HTTP requests, 15 Google HTTP checks, 10 advertisement validation checks, 24 Google claim/state/config checks, 7 Google database checks (242 checks total), plus PHP/JavaScript syntax and setup/migration verification.
- Charles's ad smoke test explicitly expects 10 offers; it passed against a separate 10-offer disposable DB/server. Standard integration/ad tests used the 20-offer demo. No test file was edited to conceal a fixture difference.
- Charles's latest application test passed unchanged against its separate Alice/Bob/TechNova/application-3 fixture, with account IDs 1/3 and matching profile IDs 1/3 in this dedicated test database. The fixture helper refuses populated account tables and requires a disposable *_test database.
- Preservation check (12 October): git diff against 70be160 for all 16 paths touched by Charles returned no changes. Kanan's 91fd292 commit remains an ancestor of the integrated main history. See the 13 October redesign section for the three presentation files changed since.

## Limits

Live Google/France Travail require real credentials/consent and are not claimed verified. France Travail remains one bounded 50-offer batch; pagination/scheduling are optional bonuses. SMTP delivery, Google account linking, password recovery, hosting and production proxy rate limits are separate features/services. Required messages/applications are stored and accessible to authorized recruiters.

Charles's recruiter writes are preserved as an existing extension; global database administration is admin-only. The historical router (api/router.php) remains intact; public/index.html was modified by the frontend redesign below and is a template served through public/index.php — opened directly, it has no translation dictionary or integration layer.

## Frontend redesign — 13 October 2026

Presentation-only work: no API route, method, parameter or JSON format, business rule, session/CSRF mechanism, database schema or server authorization check was changed.

| Area | Change |
|---|---|
| Visual system | Design tokens (colours checked against WCAG AA, system fonts, spacing, radii), header, buttons, fields, cards, panels, tables (jobboard.css, integration.css) |
| Board | Two columns on wide screens (filters, list and pagination left; sticky details right); list then details from 900 px down, with "Back to results"; compact intro; selected card state; loading/empty/error states; details list only the values the API returns (the former "Technology", "Contract to be confirmed" and "Opportunity" placeholders were removed) |
| Accounts and spaces | Sign-in collapsed into a header account menu; session-aware navigation rendered by page.php (display only — every page and API still checks access); "My space" groups applications, received applications and the recruiter area, the last two only when /api/companies/mine lists a company |
| Languages | French (default) and English: one dictionary (lang/messages.json) used by PHP rendering (public/i18n.php, Dom\HTMLDocument for index.html) and public/assets/i18n.js; keyed text and aria-labels switch in place; preference cookie alternatech_lang, separate from the session cookie; API data is never translated; known English backend messages are translated client-side, unknown ones shown as-is |

Files of Charles's 16 baseline paths changed by this work: public/index.html (header account menu, board column wrapper, "My space" section, translation keys), public/assets/login.js (text-only: interface strings through I18N, API calls and logic unchanged) and public/assets/jobboard.css (rewritten as the design system). His other 13 paths are unchanged by this work; tests/applications-http.cjs differs from 70be160 only through his own later commits. Other frontend files changed: index.php, page.php, login.php, account.php, admin.php (its panel Sign out is hidden in favour of the header one; admin.js keeps its handler), jobboard.js, google-login.js, account.js, admin.js and integration.js (text through I18N plus the layout hooks above); new: site.js, i18n.js, public/i18n.php, lang/messages.json.

Verification (isolated alternatech_frontend_test database, dedicated 8082 server, headless Edge without extra dependencies): integration HTTP and integrated advertisement HTTP suites, a 25-check recruiter API contract script, and browser scripts for the recruiter workflow (50 checks), catalogue layout and states, account menu and spaces for visitor/applicant/recruiter/admin (19 checks) and translations (16 checks: default French, in-place switch keeping drafts, persistence across pages, lang attribute, API values verbatim, backend message translation, administration, no unkeyed visible text or aria-label, 1280/768/360 px in both languages, keyboard). Those browser scripts are temporary and not part of the repository.

## Schema reconciliation — 14 October 2026

Finding: the database in use (historical) follows Charles's schema — credentials and `first_name`/`last_name` in `people`, no `users`, real `applications.application_date`, `company_members` with its own id, signed `INT` ids — which no `schema.sql` in any branch describes. `main` needs Kanan's schema (`users`, `people.firstname`, `user_id`), so sign-in, registration, guest Apply, received applications and administration fail on it (`42S02`). The earlier `migrate.php` could not migrate it and `setup.php` would have created tables before failing. `develop`, `kanan/database-api` and `charles/php-auth-recruiter` are fully contained in `main`; `charles/companies-applications` adds only an unmerged Node/Express API that duplicates PHP routes.

Changes (no route or JSON contract changed): canonical `database/schema.sql` (non-unique `people.email` and `companies.name` with lookup indexes, company → advertisements RESTRICT, `application_date DATETIME` stored with a generated `created_at`, `schema_migrations`); `tools/migration-lib.php` + rewritten `tools/migrate.php` (shape detection, refusal of unknown schemas, checks before any write, dry run by default, journaled and resumable steps, account migration keeping only bcrypt hashes and locking unrecognised values, id/content/AUTO_INCREMENT comparison, deferred cleanups never executed); `tools/setup.php` refuses an existing non-canonical database; `tools/reset-password.php`; `profileFor()` never returns profile 0; the admin API lists explicit `people` columns so the legacy `people.password` is never served; `USE alternatech;` removed from `seed.sql` and the superseded migration 001. Details, deletion matrix, procedure and rollback: [database-migration.md](database-migration.md).

Security: a MySQL `root` credential is committed in clear on `charles/companies-applications` (also on `origin`), outside `main`. It must be rotated; history is not rewritten. Root-level `*.sql` dumps are now ignored by Git. The historical data contains one legacy password in an unrecognised format (likely plaintext): it is never imported and its cleanup is a deferred, explicit step.

Verification so far: PHP/JavaScript syntax and the database-free `tests/migration/schema-migration-unit.php`. The database-level (`migration-db.php`) and HTTP (`migration-http.cjs`) migration tests are written for a disposable `*_migration_test` database and have not been run yet; no migration was run on any database.
