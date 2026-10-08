# Google login — isolated PHP module

Charles's api/server.js, api/db.js, /api/login and JWT are unchanged. New URLs are exclusively `/api/auth/google/start`, `/callback`, `/session`, `/logout`. This login gives a separate HttpOnly PHP session, not a Charles JWT; it does not authorize Applications, Apply or ad maintenance/admin writes. A shared authorization contract remains future integration work.

## Enable locally

1. Import core schema + seed; import `database/migrations/001_google_identities.sql` (also for existing installations). No ALTER of users/people is needed.
2. In Google Cloud Console create an OAuth client of type Web application and configure its consent screen / test users. Add the exact authorized redirect URI `http://127.0.0.1:8080/api/auth/google/callback` for local development. Production requires HTTPS and its own exact URI.
3. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI in local `.env`. Enable pdo_mysql and curl; configure a trusted CA bundle for curl if your PHP installation needs one. Do not disable TLS validation.
4. Start `php -S 127.0.0.1:8080 -t public api/router.php`. Continue with Google performs a full-page provider redirect; no popup. Missing credentials disable only Google login, not the job board.

## Security and account behavior

Authorization code flow uses random single-use state/nonce, 10 minute timeout, PKCE S256 and fixed Google HTTPS endpoint with certificate verification. ID tokens are obtained exclusively through the authenticated server-to-Google code exchange, never accepted from frontend input. Google's documentation explicitly allows trusting tokens obtained through this direct HTTPS exchange; issuer/audience/expiry/nonce/verified-email/sub are still checked. No tokeninfo debugging endpoint or unsigned client token authentication is used. Official reference: https://developers.google.com/identity/openid-connect/openid-connect#obtainuserinfo .

Google `sub` is the immutable account identifier, stored in a separate case-sensitive google_identities table linked to users. New users get role=user and a random password hash, with no usable local password. An existing email without a matching Google identity returns 409; automatic account linking is deliberately refused. Existing Google identities retain their user ID when their Google email changes. The users email remains the original profile email; profile/email change management is outside this module.

Sessions have a separate name, HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, session-ID rotation on login and a 1-hour absolute lifetime. Logout requires a session CSRF token. Provider tokens, secrets and SQL error messages are not returned or logged. DB role is read again by /session. No Google access/refresh token is retained.

## Tests and limitations

Run `php tests/google-auth.php` for claim/state validation. `php tests/google-db.php` optionally uses GOOGLE_TEST_DB=1 and a disposable database selected by DB_*; it creates/deletes only its own test users and identities. Tests use synthetic tokens ONLY to exercise claim validation, not as proof of provider authentication. Live provider authorization requires real credentials and browser consent, and remains unverified without them.

The optional migration is additive. Charles's existing schema/auth mismatches are documented in docs/kanan-setup.md; this module does not change his code or claim to solve those integration issues.

Executed checks: 24 state/claim/config checks, 7 database/account checks, 15 Google HTTP checks, local authenticated-session/expiry/CSRF/logout checks, and all 22 advertisements HTTP regression checks passed. Core schema was imported into a fresh MariaDB test database; repeated seed and repeated optional migration succeeded with 7 tables and 20 offers. Missing Google credentials were tested separately: /start returns generic 503, /session reports disabled, and the job board still returns its 20 offers. PHP and JavaScript syntax checks passed. No live Google authorization was performed.

Google HTTP tests: `node tests/google-http.cjs` against a disposable configured local server (default 127.0.0.1:8080; override GOOGLE_HTTP_TEST_URL). Dummy local OAuth configuration is sufficient for these redirect/error-path tests; they never follow the redirect or complete live provider authentication.
