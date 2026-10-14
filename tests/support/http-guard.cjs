'use strict';
const assert = require('node:assert/strict');
function installHttpGuard(variable = 'TEST_API_URL') {
  const raw = process.env[variable], database = process.env.TEST_DB_NAME, runId = process.env.TEST_RUN_ID;
  assert.ok(raw, `${variable} is required; no default server is allowed`);
  const base = new URL(raw);
  assert.ok(base.protocol === 'http:' && ['127.0.0.1', '[::1]'].includes(base.hostname) && base.port && !['8080', '8086'].includes(base.port), 'Use a dedicated loopback test port, never 8080/8086');
  assert.ok(!base.username && !base.password && base.pathname === '/' && !base.search && !base.hash, 'Test URL must contain only an origin');
  assert.match(database || '', /^[a-zA-Z0-9_]+_test$/, 'TEST_DB_NAME must end in _test');
  assert.match(runId || '', /^[a-f0-9]{32,64}$/, 'TEST_RUN_ID is required');
  const original = globalThis.fetch;
  let ready;
  async function attest() {
    const response = await original(base.origin + '/__isolation/db', {redirect: 'manual', signal: AbortSignal.timeout(5000)});
    assert.equal(response.status, 200, 'Test isolation probe unavailable');
    const info = await response.json();
    assert.equal(info.select_database, database, 'Wrong server database');
    assert.equal(info.test_run_id, runId, 'Wrong test server/run');
  }
  globalThis.fetch = async (input, options = {}) => {
    const url = new URL(typeof input === 'string' ? input : input.url || String(input));
    assert.equal(url.origin, base.origin, 'Requests outside the isolated server are forbidden');
    await (ready ??= attest());
    if (!['GET','HEAD'].includes((options.method || 'GET').toUpperCase())) await attest();
    return original(input, {...options, redirect: 'manual', signal: options.signal || AbortSignal.timeout(15000)});
  };
  return base.origin;
}
function verifyFixtures(fixtures) {
  assert.equal(fixtures.database, process.env.TEST_DB_NAME, 'Fixtures belong to a different database');
  assert.equal(fixtures.test_run_id, process.env.TEST_RUN_ID, 'Stale fixtures from another test run');
}
module.exports = {installHttpGuard, verifyFixtures};
