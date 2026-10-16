# Final review — 16 October 2026

Work is based on `Kanan-fix` (capital K). The requested new display name, “Kanan final fix”, contains spaces, which Git forbids; the publish branch is `Kanan-final-fix`. No merge into main or changes to other remote branches are required.

## All eight remote branches compared

Snapshot before publishing:

| Branch | Head | Findings and retained capability |
| --- | --- | --- |
| `develop` | `0917d1a` | Initial scaffold; ancestor of the integrated implementation. |
| `kanan/database-api` | `91fd292` | PHP schema, catalog and Google authentication; present in the integrated implementation. |
| `charles/php-auth-recruiter` | `7457d7f` | Password login, company memberships, advertisements and application workflows; retained. |
| `charles/companies-applications` | `e89b647` | Divergent early Express implementation: company advertisements, ad detail/create and JWT login. The corresponding user capabilities exist in the canonical PHP app. The historical server and its incompatible authentication are left on their original branch, not reintroduced as a second backend. |
| `main` | `016c7c6` | Integrated accounts, recruiter ad management, applications and eight-table admin CRUD; retained. |
| `chore/post-migration-checkpoint` | `fd4cb2a` | Migration tooling and bilingual interface; retained. |
| `fix/e2e-functional-issues` | `f500726` | Sibling fixes for retired sessions and guest duplication. The safe session-retirement approach is carried forward. Guest deduplication keeps `Kanan-fix`'s documented session rule rather than globally associating separate guests by unverified email. |
| `Kanan-fix` | `d7128cb` | Most complete baseline: guarded tests, rate limits, canonical accounts, migration tools, bilingual frontend, recruiter/admin flows and isolated importer identities. All retained. |

Compared branch ancestry, commits, changed paths and overlapping implementations. Historical branches were inspected, not each independently deployed or certified. No existing files or functional routes are removed by this change.

## Functional fixes

- Retire an old session ID with an anonymous marker before regenerating it. A delayed request using the old cookie cannot issue a replacement anonymous cookie that overwrites the successful login. The old ID retains neither identity nor CSRF token.
- Publish an explicit successful-login event. The board updates user navigation and application state from that event; administrator login redirects directly to administration. Session refreshes ignore older responses. Logout notices stay attached after rendering an existing signed-in session.
- Admin table loads ignore outdated responses, clear stale actions during loading, and disable pagination until the new page arrives. Saving a record locks the resource selector until completion so the editor cannot switch tables mid-save. Create controls remain unavailable until metadata/table loading completes.
- The admin regression was also run against the original `admin.js` and failed specifically at “Delayed company response cannot replace category rows”; it passes with the fix.

## Frontend

The board has a navy hero, restrained decorative rings, clearer cards, colored contract badges and a softer background. Existing search, sticky details, inline applications and recruiter areas remain. Account pages have a centered form panel, full-width primary action and a responsive profile grid. Admin tables have sticky headings and a bounded scrolling area. Mobile navigation fits together more compactly; forms collapse to one column. French/English switching, focus indicators and reduced-motion support remain.

## Actual verification

Executed against PHP 8.4, Node and headless Microsoft Edge with synthetic fixtures on `alternatech_review_test`, a separate MariaDB instance bound to loopback port 3318, and the guarded PHP server on port 8099. No real application database or user credentials were used.

- **23 advertisement HTTP checks:** seed listing, CRUD, validation, protected writes and private-file blocking.
- **130 integration HTTP checks:** registration, profile update/current-password protection, guest and account applications, duplicates, CSRF, user/admin permissions, recruiter isolation/CRUD, shared Google session, all eight admin tables and pagination.
- **30 authentication/session checks:** bad passwords, role redirects, cookie rotation, anonymous retired sessions, no replacement cookie from a late old-session request, invalid CSRF, permission checks and logout.
- **18 real browser checks:** failed and successful login, inline user/admin login, reload persistence, profile editing, application draft retained across a language switch, delayed admin response, category creation/deletion, 390 px layout and return-to-results keyboard focus. No uncaught page JavaScript errors.
- Google identity database checks: creation, stable provider identity, no automatic email linking, role retention and cascade cleanup. Provider claims/state validation also passed without a database.
- Maintenance and importer database checks passed: guarded legacy cleanup and stable, isolated imported employers.
- Database-free test guards, catalog cancellation/stale responses, login rate limits, advertisement validation and **105 schema-migration unit checks** passed.
- PHP and JavaScript syntax checks and Git whitespace checks passed. Desktop, login and mobile screenshots were visually inspected.

The available local database binary is MariaDB **10.4.32**, older than the documented MariaDB 10.6+/MySQL 8 deployment requirement. These results validate the canonical app on this isolated local instance; full historical migration on a supported production database was not rerun. Real Google consent/token exchange and live France Travail requests were not tested because provider credentials were not configured. Their optional implementations remain in place.
