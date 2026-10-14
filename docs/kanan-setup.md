# Kanan / Charles integrated setup

See [README](../README.md) for current setup, routes and tests.

Run `php tools/setup.php --demo` for a fresh empty DB, for an empty database. For an existing database, follow `docs/database-migration.md`; `setup.php` refuses non-canonical installations. Start with `php -S 127.0.0.1:8080 -t public api/integration-router.php`. Serve only public/, keep credentials in .env and use a restricted runtime DB user.

Charles's 16 changed paths remain unchanged from main commit 70be160: PHP handlers/router, login/recruiter JS, HTML, CSS and his application/advertisement tests. Both concurrent test updates were incorporated before the final push. The additive integration router supplies canonical users/password_hash authentication and delegates his recruiter/advertisement/application endpoints. Sessions explicitly bridge account/profile IDs. The schema supplies membership, creator, unique application and compatible date fields.

The runtime uses cookies/CSRF and company membership/admin checks, not ADS_WRITE_TOKEN. Google now uses the same session. The old Node branch is historical and outside the PHP runtime. See [requirement audit](integration-audit.md).
