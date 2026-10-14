'use strict';
// HTTP checks against a server whose database was migrated by tests/migration/migration-db.php.
//   TEST_API_URL=http://127.0.0.1:<port> MIGRATION_TEST_DB_NAME=<name> node tests/migration/migration-http.cjs
// Refuses to run unless <url>/__isolation/db reports that exact database. Writes only to that disposable database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.TEST_API_URL, expected = process.env.MIGRATION_TEST_DB_NAME;
const fx = JSON.parse(fs.readFileSync(__dirname + '/../.runtime-migration-fixtures.json', 'utf8'));
let checks = 0;
class Client {
  cookie = ''; token = '';
  async req(path, status = 200, method = 'GET', body) {
    const r = await fetch(base + path, { method, redirect: 'manual', headers: { Accept: 'application/json', Cookie: this.cookie, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(this.token ? { 'X-CSRF-Token': this.token } : {}) }, body: body === undefined ? undefined : JSON.stringify(body) });
    for (const c of r.headers.getSetCookie()) { const e = c.split(';')[0]; if (e.startsWith('alternatech_login=')) this.cookie = e; }
    const text = await r.text(); assert.equal(r.status, status, `${method} ${path} -> ${r.status}`); checks++;
    const data = text ? JSON.parse(text) : null; if (data?.csrf_token) this.token = data.csrf_token; return data;
  }
  login(profile, status = 200) { return this.req('/api/auth/login', status, 'POST', { email: fx.emails[profile], password: fx.passwords[profile] ?? 'not-the-password-123' }); }
}
(async () => {
  assert.ok(base && expected, 'set TEST_API_URL and MIGRATION_TEST_DB_NAME');
  const probe = await (await fetch(base + '/__isolation/db')).json();
  assert.equal(probe.select_database, expected, 'server must use the migrated test database'); assert.equal(fx.database, expected);
  // Migrated accounts: bcrypt hashes work (the $2b$ one twice: rehash on first login), the unrecognised one is locked.
  const p1 = new Client(), p2 = new Client(), p3 = new Client();
  for (const [c, n] of [[p1, 1], [p2, 2], [p3, 3]]) { const r = await c.login(n); assert.equal(r.user.person_id, n, 'session uses the historical profile id'); }
  await new Client().login(2);
  await new Client().login(4, 401);
  // Two recruiters of company 1 publish; a non-member is refused.
  const ad = { title: 'Migration HTTP offer', short_description: 'Summary', description: 'Description', location: 'Nantes', working_time: '35 hours/week', salary: null, contract_type: null, company_id: 1, category_id: 1 };
  const a1 = await p1.req('/api/ads', 201, 'POST', ad), a2 = await p2.req('/api/ads', 201, 'POST', { ...ad, title: 'Migration HTTP offer 2' });
  assert.equal(Number(a1.created_by), 1); assert.equal(Number(a2.created_by), 2);
  await p3.req('/api/ads', 403, 'POST', ad);
  // Historical applications keep their ids and owners; duplicates are refused.
  assert.deepEqual((await p3.req('/api/applications/mine')).applications.map(a => Number(a.id)).sort(), [1, 5]);
  await p3.req('/api/ads/1/applications', 409, 'POST', { message: 'Duplicate' });
  const received = await p1.req('/api/recruiter/applications?per_page=100');
  assert.ok([1, 2, 3, 5].every(id => received.applications.some(a => Number(a.application_id) === id)), 'recruiters see the historical applications');
  assert.ok(received.applications.every(a => a.created_at), 'created_at alias is served');
  // Guests may reuse an e-mail; a later account with that e-mail is never linked to their applications.
  const guestMail = 'migration-guest@example.invalid';
  for (const id of [a1.id, a2.id]) { const g = new Client(); await g.req('/api/auth/csrf'); await g.req(`/api/ads/${id}/applications`, 201, 'POST', { firstname: 'Guest', lastname: 'Fixture', email: guestMail }); }
  const fresh = new Client(); await fresh.req('/api/auth/csrf');
  await fresh.req('/api/auth/register', 201, 'POST', { firstname: 'Guest', lastname: 'Account', email: guestMail, password: 'Migration-' + Date.now() });
  assert.deepEqual((await fresh.req('/api/applications/mine')).applications, [], 'no automatic link by e-mail');
  // Account update on a migrated account.
  const updated = await p3.req('/api/account', 200, 'PATCH', { phone: '0102030405' }); assert.equal(updated.user.phone, '0102030405');
  // Administration never returns credentials, including the legacy people.password column.
  const admin = new Client(); await admin.login(fx.admin_profile);
  for (const table of ['people', 'users']) { const rows = await admin.req(`/api/admin/${table}?per_page=100`); assert.ok(rows.items.every(r => !('password' in r) && !('password_hash' in r)), `admin ${table} without credentials`); }
  // A company that still has advertisements cannot be deleted.
  await admin.req('/api/admin/companies/1', 409, 'DELETE');
  for (const a of [a1, a2]) await p1.req(`/api/ads/${a.id}`, 204, 'DELETE');
  console.log(`PASS ${checks} migration HTTP checks on ${expected}`);
})().catch(e => { console.error(e.message); process.exitCode = 1; });
