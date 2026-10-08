# Kanan database and advertisements handoff

PHP 8.4 with PDO and pdo_mysql; MySQL 8 or MariaDB 10.6+.
Only `public/` is web accessible. Never serve the repository root (it contains `.env`).

## Local setup (PowerShell)

1. Create `alternatech` via `database/schema.sql` in your SQL client, then import `database/seed.sql`. No DROP statements. Use a fresh development database; IF NOT EXISTS does not migrate older tables. Seed is fictional and uses IDs 1–20, so do not rerun it on production data.
2. Copy `.env.example` to `.env`; set database credentials for a dedicated user with SELECT/INSERT/UPDATE/DELETE access to `alternatech`. Schema import requires separate creation privileges.
3. Enable `extension=pdo_mysql` in your PHP configuration. Optional importer also needs curl and mbstring.
4. From the repository root:

```powershell
php -S 127.0.0.1:8080 -t public api/router.php
```

Visit http://127.0.0.1:8080. Database configuration failures return a generic JSON error, never credentials. Missing DB is a setup error, not a silent fixture fallback.

## API contract

GET `/api/ads` returns an array; GET `/api/ads/{id}` returns an object. Both include company/category display names alongside IDs and source metadata. No pagination. POST on collection creates (201 + Location), PUT on item replaces editable fields (200), PATCH updates supplied fields (200), DELETE returns 204. Unknown records return 404; methods 405; bad JSON 400; validation 422; wrong media type 415; oversized body 413; relationship conflicts 409; unavailable DB 503.

Write operations are disabled until ADS_WRITE_TOKEN has at least 32 characters. Pass `Authorization: Bearer <token>` from a trusted server or local API client; never put it in frontend JavaScript. This temporary maintenance credential is independent of Charles's login/JWT flow. It must be replaced by agreed admin authorization before integrating admin CRUD. Keep server localhost for development and use HTTPS for deployment.

POST/PUT required: title (200 characters), short_description (500), description (16000), location (150), company_id (positive JSON integer). Optional: category_id (integer/null), salary (100), working_time (100), contract_type (100). PATCH requires at least one supported field. PUT clears omitted optional fields. source/external_id/source_url are read-only to REST clients and managed by importer. Deleting an advertisement cascades its applications, per schema; callers must deliberately opt into this destructive action.

## Charles integration

No changes to api/server.js, api/db.js, Companies, Applications, Apply, admin or pagination. Frontend dispatches `alternatech:ad-selected` with `event.detail.advertisement` after loading details. It contains no Apply backend/form.

Remote Charles branch currently uses Express for `/api/ads/:id`, POST `/api/ads` and `/api/login`. Those URLs overlap semantically with this PHP API although file paths do not conflict. Agree a single owner or reverse-proxy routing before combining servers. His login queries `people.password/role`, while this schema intentionally keeps credentials in `users.password_hash/role`. His create-ad handler expects `company_members` and `advertisements.created_by`, neither in the agreed six-table schema. Password-based auth remains Charles's responsibility. An isolated Google login is now provided under /api/auth/google/*; see docs/google-login.md. It does not produce Charles JWTs. His nullable-category listing uses INNER JOIN, which omits imported offers with no category; it needs LEFT JOIN when integrated. An exposed database password exists on that remote branch; Charles should remove it from code and rotate it. No value is reproduced here.

## France Travail bonus

Optional CLI scaffold:

```powershell
php api/integrations/france-travail/sync.php informatique
```

Set FRANCE_TRAVAIL_CLIENT_ID / CLIENT_SECRET / SCOPE in local `.env`. Missing credentials exit 2 before any DB/network work. One batch of up to 50 offers is fetched (no pagination). Provider OAuth + response mapping require live credential verification. Official provider portal: https://francetravail.io/data/api/offres-emploi . Unique `(source, external_id)` makes repeated imports update the existing advertisement, with transaction rollback on failure. Companies are reused by name; categories are left null rather than guessed. Run imports serially. Seed listings work without these credentials. No scheduled jobs or paid services are configured.

## Checks

```powershell
Get-ChildItem api,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php tests/ads-validation.php
node --check public/assets/jobboard.js
```

After DB setup, verify list/detail plus create, PATCH, PUT, DELETE and FK behavior against the actual DB. Use a dedicated temporary job and delete it afterwards. Do not commit `.env` or local runtime files.

HTTP smoke test (fresh demo DB with 20 seed jobs; disposable test instance only): set TEST_API_URL and ADS_WRITE_TOKEN in the shell, then `node tests/ads-http.cjs`. It creates and deletes its own temporary ad. Executed with PHP 8.4.25 and portable MariaDB 11.4.5: schema import, seed twice, 10 validation cases, 22 HTTP cases, FK cascade/SET NULL, external-id uniqueness, DB-offline generic 503, and writes-disabled 503 passed. The optional live provider sync was not tested without credentials. Browser automation was unavailable (browser kernel exited), so visual and interactive browser checks remain unverified. Test processes were stopped; no machine service was installed.
