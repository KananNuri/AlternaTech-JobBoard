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
| Preserve Charles | All 16 paths touched by his nine commits remain unchanged |
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

Use api/integration-router.php. It delegates Charles's recruiter/advertisement/application handlers, supplies canonical auth and routes new account/catalog/guest-apply/admin APIs. public/index.php adds scripts/navigation to the existing HTML without rewriting it; integration CSS extends the existing stylesheet. Credentials are not duplicated into people.

Migration is additive/idempotent and refuses duplicate profiles/applications rather than deleting history. MySQL/MariaDB DDL is not transactional: back up first and rerun after resolving a conflict. Legacy sessions are invalidated. CLI provisions admin; public signup cannot choose privileged roles.

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
- Preservation check: git diff against 70be160 for all 16 paths touched by Charles returned no changes. Kanan's 91fd292 commit remains an ancestor of the integrated main history.

## Limits

Live Google/France Travail require real credentials/consent and are not claimed verified. France Travail remains one bounded 50-offer batch; pagination/scheduling are optional bonuses. SMTP delivery, Google account linking, password recovery, hosting and production proxy rate limits are separate features/services. Required messages/applications are stored and accessible to authorized recruiters.

Charles's recruiter writes are preserved as an existing extension; global database administration is admin-only. Historical router/HTML remain intact for comparison; the documented integration entry point/root PHP page runs the complete app.
