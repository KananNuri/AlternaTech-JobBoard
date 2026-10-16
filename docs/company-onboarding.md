# Recruiter onboarding and company authority

The route is `/recruit.php`, available from “Recruit for a company” after signing in. Candidate applications remain available to every account; applying never requires company membership. Public registration still cannot choose a privileged role.

## Who can authorize a recruiter?

1. A signed-in user submits a company creation request, including a contact email and supporting details about their authority. Website and registration number are optional supporting evidence. No company or recruiter privileges are created yet. One pending creation request per account prevents accidental duplicates.
2. A **platform administrator** reviews the request in the same page, verifies identity, company registration and authority through an independent company contact, and checks that the company is not already listed. The software records the decision; it does not claim to perform registry or identity verification automatically. A name or matching email domain is not proof. Approval atomically creates the company, grants its applicant an ordinary recruiter membership and designates that person as company manager. Rejection leaves their candidate account unchanged.
3. A new colleague searches for an existing company, chooses it, and submits supporting details. Its manager reviews and approves/rejects this request. Approval creates a recruiter membership. Ordinary recruiters cannot approve colleagues or appoint themselves as manager. The manager can revoke colleague access with inline confirmation; the existing ad and application handlers recheck membership, so revocation takes effect on subsequent requests.
4. An existing company with no manager must be verified by a platform administrator. The administrator searches for the company and assigns an **existing account by email**. Neither requesting to join nor matching the company email grants authority. Pending join requests become visible to the appointed manager.
5. Only a platform administrator appoints/replaces the manager. There is one manager per company. Replacement preserves the former manager’s ordinary recruiter membership and removes their management powers immediately. The new manager can revoke that remaining recruiter access. The company-access endpoint refuses removal of the current manager; appoint a replacement first. Platform administrators retain the existing generic emergency CRUD powers.

This MVP implements join requests rather than email invitations: there is no SMTP claim, public invitation token, automatic domain verification or company identity verification service. Managers can approve only requests for their own company. They can see the requesting colleague’s account name, email and supporting message; these are not exposed to other companies or ordinary recruiters.

## Storage and installation

Existing `company_members.role='recruiter'` is unchanged. `company_managers` designates one of those memberships as responsible for access management. Its composite foreign key prevents a manager without a corresponding recruiter membership. New `company_proposals` and `company_join_requests` store pending/rejected/approved states, review timestamps and reviewer account IDs. Email is not used as a company ownership identifier.

Fresh `php tools/setup.php --demo` installs the extension with the canonical schema. For an **existing canonical database**, back it up and run with setup credentials:

```powershell
php tools/install-company-access.php
# Read-only preview above. Apply only to the explicitly named database:
php tools/install-company-access.php --apply --database=<exact-name> --confirm-backup
```

This is additive DDL: no existing rows are modified and no first manager is silently chosen. The installer supports the signed/unsigned canonical identifier types of existing installations. It can be rerun after interruption. As with other MySQL DDL, table creation is not one rollbackable transaction. `tools/setup.php` still does not alter an existing database. Historical databases must complete the canonical migration before installing the extension. Runtime code never creates tables.

The state page shows up to 100 entries in each section. Company search requires at least two characters and returns up to 30 matches; refine the company name when needed. Company names are not unique, so administrators must resolve existing-company matches before approving creation requests. All entries in the pending queues remain processable as reviewed entries leave the queue. Large-team pagination, audit-history retention beyond live accounts and email invitations are future enhancements.

## APIs

All routes below require a current account. Writes require the session CSRF token; JSON request fields are explicitly validated.

| Route | Method | Authorization |
| --- | --- | --- |
| `/api/company-access` | GET | Own companies/request statuses; manager inbox/team; admin creation queue |
| `/api/company-access/search?q=...` | GET | Signed-in company name search; no private contacts |
| `/api/company-access/proposals` | POST | Signed-in candidate; pending request only |
| `/api/company-access/proposals/{id}/decision` | POST | Platform admin; `decision: approve/reject` |
| `/api/company-access/companies/{id}/join` | POST | Signed-in nonmember; `message` |
| `/api/company-access/joins/{id}/decision` | POST | That company’s manager or platform admin |
| `/api/company-access/companies/{id}/manager` | POST | Platform admin; existing account `email` |
| `/api/company-access/companies/{id}/members/{personId}` | DELETE | That company’s manager or platform admin; current manager protected |

Creation/decisions use transactions, locking and uniqueness constraints. Replaying a decision or duplicate pending request is rejected. Rejected joins and previously revoked recruiters may request access again. There is no privilege selection in the public request payload.

## Test workflow

Prepare a fresh disposable database, guarded server and synthetic fixtures as documented in `test-isolation.md`. The new HTTP suite covers both Charles’s onboarding routes and the negative permission boundaries; the browser suite starts with two new candidate registrations and walks creation → admin approval → colleague join → manager approval → revocation, then checks mobile layout and translation draft retention.

With Playwright/browser configured as described in README and all guarded test environment variables set, run `node tests/total-review.cjs` **after pushing and confirming the checked-out SHA matches the remote branch**. It runs the new tests, existing browser/auth/ad/integration suites, database-free guards and migration units, plus Google identity/maintenance/importer database regressions. The full integration suite runs last because it deletes baseline fixture accounts. Do not rerun it on stale fixtures; prepare a fresh database/run.

The total runner does not claim real Google OAuth, live France Travail or historical migration coverage. Final run results and the exact pushed SHA belong in the delivery report; this document describes the reproducible procedure.
