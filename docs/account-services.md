# Account services and background jobs

## Upgrade

Back up the database, deploy the source and `composer.lock`, run `composer install --no-dev --prefer-dist`, then `php tools/migrate.php`. PHP needs PDO MySQL, curl, mbstring and openssl; SMTP is provided by PHPMailer. Keep the document root at `public/` and the integration router as the API entry point. No production credentials or real email addresses are supplied by the repository.

The migration adds `users.email_verified_at`, `users.auth_version`, and three internal tables: `auth_rate_limits`, `account_tokens`, `mail_outbox`. The original eight admin resources remain available. Internal token and mail tables deliberately have no admin browser CRUD API. Database connections use UTC. For a remote database, set `DB_SSL_CA` to a trusted PEM CA file; certificate verification remains enabled. Existing sessions without an auth version must sign in again after this upgrade.

## Email setup

Set `APP_URL` to the deployment's HTTPS origin, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY` (`tls` for STARTTLS or `smtps` for implicit TLS), `SMTP_USERNAME`, `SMTP_PASSWORD`, and `MAIL_FROM` in the private `.env`. `APP_URL` must have no credentials, path, query or fragment; links never use a caller's Host header. `http` and plaintext SMTP are accepted only on loopback for local tests. The app does not send email when SMTP is not configured; password-reset requests then report an unavailable email service instead of pretending delivery succeeded.

Run `php tools/send-mail.php` every minute using your deployment scheduler. A sample Linux crontab is in `ops/alternatech.cron.example`; on Windows use Task Scheduler with the PHP executable, argument `tools/send-mail.php`, and the repository as the working directory. Installing the sample scheduler is a deployment step, not performed by migrations or page visits. The worker handles up to 50 messages per run, coordinates concurrent workers through row locks, retries failures with a bounded backoff, and returns a failure exit code if delivery fails. A crash after SMTP accepts a message but before SQL commits may cause duplicate delivery; delivery is at least once. Sent, expired and five-times-failed messages are removed. Monitor the exit status and generic failure log; failed mail can be requested again from the account page. Restrict access to `.env`, database backups and the outbox: queued account emails temporarily contain bearer links.

Registration queues a verification email. Users can resend from `/account.php`; verifying is an explicit action on `/security.php`, so email scanners do not consume GET links. Verification is optional for existing job-board access; only verified account addresses receive application notifications. Changing an email clears its verification and invalidates old account links. Recruiters receive a generic notification for new applications to their assigned companies; verified candidates receive status updates. Messages and candidate details remain inside the authenticated application, not email bodies. No guest address is assumed verified.

## Recovery and Google linking

`/security.php` requests password resets. Responses do not reveal whether an account exists. Reset links expire after 30 minutes; verification links after 24 hours. The database stores token hashes; URLs put tokens in the fragment to keep them out of HTTP access logs and Referer headers. Tokens are single use, bound to their purpose and current account email, and require POST plus CSRF. Password reset revokes all existing account sessions. Normal password changes revoke other sessions and outstanding reset links. Google-only users can establish a local password through email recovery if delivery is configured.

Google linking is initiated from `/account.php`, requires a signed-in session, CSRF and the current local password, then a fresh Google OAuth state/nonce/PKCE exchange. The callback checks the same account and auth version before linking. Existing identities cannot be reassigned and existing account links cannot be silently replaced. Google proves only its own verified email; a differing local email is not marked verified. Automatic linking merely because two email strings match remains forbidden. Live Google consent/exchange still needs valid OAuth credentials.

Login throttling is stored in SQL per peer IP and normalized email, so replacing cookies does not reset the limit. `REMOTE_ADDR` is used, never arbitrary forwarded headers. A reverse proxy should add its own rate limits; behind a proxy, the application IP bucket may be shared by all visitors. Run `php tools/maintenance.php` daily to remove expired limiter, token and mail rows.

## France Travail and automation

The importer requests pages of 50 offers until the final page or `FRANCE_TRAVAIL_MAX_OFFERS` (default 1000; range 50–3000 in multiples of 50). This bound keeps runs finite; if reached, the command reports truncation, so narrow the search or adjust the limit. Per-page transactions preserve completed pages on a later provider outage; retries update `(source, external_id)` instead of duplicating offers. A database advisory lock prevents overlapping imports. This is a paginated snapshot import, not deletion of provider offers that disappeared upstream.

`.github/workflows/france-travail.yml` offers a daily 05:17 UTC schedule and manual dispatch. It skips when optional secrets are missing. Configure `FRANCE_TRAVAIL_CLIENT_ID`, `FRANCE_TRAVAIL_CLIENT_SECRET`, `SYNC_DB_HOST`, `SYNC_DB_PORT`, `SYNC_DB_NAME`, `SYNC_DB_USER`, `SYNC_DB_PASSWORD` and `SYNC_DB_CA_PEM` in GitHub Actions. Use a restricted database account, trusted TLS CA and network rules suitable for the runner. If the database is private, prefer the server-local cron example instead of opening it publicly. No migration is run by this scheduled workflow. GitHub schedules may be delayed.

`.github/workflows/ci.yml` runs syntax, unit, race-condition, SQL and HTTP/security tests against a disposable MariaDB service on pushes and pull requests. It uses synthetic accounts and a loopback SMTP sink; no Google, SMTP or France Travail production credentials are used. Live external providers remain separate deployment checks.

## Added API contracts

All write routes below require JSON and a CSRF token from `GET /api/auth/csrf`.

| Route | Method | Body / response |
|---|---|---|
| `/api/auth/password/request` | POST | `{email}` → generic `{message}` |
| `/api/auth/password/reset` | POST | `{token,password}` → `{message}` |
| `/api/auth/email/request` | POST | Signed-in account, `{}` → `{message}` |
| `/api/auth/email/verify` | POST | `{token}` → `{message}` |
| `/api/auth/google/link` | POST | Signed-in account, `{current_password}` → `{url}` for fresh provider consent |
| `/api/recruiter/ads` | GET | Signed in; optional `company_id`, `page`, `per_page`; `{items,page,per_page,total,pages}` |

Session JSON additionally includes `email_enabled`; the user includes nullable `email_verified_at`. Existing API contracts remain supported. `308` redirects `/index.html` and `/index.php` to `/` so every entry point loads the integrated application.
