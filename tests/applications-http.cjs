const assert = require('node:assert/strict');

const base = process.env.TEST_API_URL || 'http://127.0.0.1:8080';

async function request(path, expectedStatus, options = {}) {
  const response = await fetch(base + path, options);
  const text = await response.text();

  assert.equal(
    response.status,
    expectedStatus,
    `${options.method || 'GET'} ${path}: ${text}`
  );

  return text ? JSON.parse(text) : null;
}

(async () => {
  // 1. Les routes de consultation exigent une session.
  await request('/api/applications/mine', 401);
  await request('/api/companies/mine/applications', 401);

  // 2. Les méthodes non autorisées doivent être refusées.
  await request('/api/applications/mine', 405, {
    method: 'DELETE',
  });

  await request('/api/companies/mine/applications', 405, {
    method: 'POST',
  });

  // 3. Une connexion invalide doit être refusée.
  await request('/api/auth/login', 401, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'invalid@example.invalid',
      password: 'wrong-password',
    }),
  });

  console.log(
    'PASS application HTTP checks: session required, methods restricted, invalid login rejected'
  );
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});