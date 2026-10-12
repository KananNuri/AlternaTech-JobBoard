# Google sign-in

Google and password sign-in share one HttpOnly alternatech_login session, canonical users account and linked people profile. Both can Apply/use assigned recruiter memberships. Sessions rotate on sign-in, use SameSite=Lax, are Secure on HTTPS and expire after one hour. Logout from either API ends the session. Protected requests revalidate the account/role in SQL.

## Enable

1. Run `php tools/setup.php` (or migrate.php after installing the core schema).
2. Configure a Google Web application OAuth client, consent/test users and exact redirect URI `http://127.0.0.1:8080/api/auth/google/callback`. Production requires HTTPS and its own exact URI.
3. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI in private .env. Enable curl/pdo_mysql and a trusted CA bundle; never disable certificate checks.
4. Start with `php -S 127.0.0.1:8080 -t public api/integration-router.php`.

Missing credentials disable only Google; the board/password login remain available.

## Behavior and security

The code flow uses single-use random state/nonce, 10-minute pending expiry, PKCE S256 and Google's fixed HTTPS token endpoint. Claims validate issuer/audience/expiry/nonce/verified email/stable subject. Tokens are accepted only through the server-authenticated Google exchange, never browser-supplied ID tokens. [Google OpenID Connect reference](https://developers.google.com/identity/openid-connect/openid-connect#obtainuserinfo).

The case-sensitive sub links to users.id. Email never automatically merges accounts: an existing email without a verified identity link returns 409. New accounts have role=user and an unusable random password hash. Existing users retain roles/phone/profile data. Provider secrets/access/refresh tokens are not stored in browser code.

Google-only users can edit name/phone. Email/local-password changes require an existing local password; verified account linking/recovery remains a future feature.

## Tests

- google-auth.php: 24 claim/state/config checks.
- google-db.php: GOOGLE_TEST_DB=1 with a disposable DB.
- google-http.cjs: dummy local OAuth client/curl; set GOOGLE_HTTP_TEST_URL as needed.
- Integration HTTP tests verify both session endpoints expose the same account and Google logout ends the shared session.

No live Google consent/token exchange was performed without credentials. Synthetic claim fixtures are validation tests, not provider authentication.
