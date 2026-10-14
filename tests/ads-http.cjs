const assert = require('node:assert/strict');

const base=require('./support/http-guard.cjs').installHttpGuard('TEST_API_URL');
const email = process.env.TEST_RECRUITER_EMAIL;
const password = process.env.TEST_RECRUITER_PASSWORD;

let checks = 0;
let cookie = '';
let csrfToken = '';

async function req(path, expectedStatus, method = 'GET', body, headers = {}) {
  const requestHeaders = {
    Accept: 'application/json',
    ...headers,
  };

  if (cookie) {
    requestHeaders.Cookie = cookie;
  }

  if (body !== undefined) {
    requestHeaders['Content-Type'] = 'application/json';
  }

  if (method !== 'GET' && csrfToken) {
    requestHeaders['X-CSRF-Token'] = csrfToken;
  }

  const response = await fetch(base + path, {
    method,
    headers: requestHeaders,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const setCookie = response.headers.get('set-cookie');

  if (setCookie) {
    cookie = setCookie.split(';')[0];
  }

  const text = await response.text();

  assert.equal(
    response.status,
    expectedStatus,
    `${method} ${path}: ${text}`
  );

  checks++;

  return text ? JSON.parse(text) : null;
}

async function login() {
  assert.ok(email, 'TEST_RECRUITER_EMAIL must be configured');
  assert.ok(password, 'TEST_RECRUITER_PASSWORD must be configured');

  const response = await fetch(base + '/api/auth/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email, password }),
  });

  const setCookie = response.headers.get('set-cookie');

  assert.equal(
    response.status,
    200,
    `POST /api/auth/login: ${await response.clone().text()}`
  );

  assert.ok(setCookie, 'Login must set a session cookie');

  cookie = setCookie.split(';')[0];

  const data = await response.json();

  assert.equal(typeof data.csrf_token, 'string');
  assert.ok(data.csrf_token.length > 0, 'Login must return a CSRF token');

  csrfToken = data.csrf_token;
  checks++;
}

(async () => {
  // 1. Connexion du recruteur et vérification de la session.
  await login();

  const session = await req('/api/auth/session', 200);
  assert.ok(session.user, 'Session must contain a user');

  // 2. Consultation des annonces.
  const ads = await req('/api/ads', 200);
  assert.equal(ads.length, 10, 'Expected 10 local seed advertisements');

  const detail = await req('/api/ads/1', 200);
  assert.ok(
    detail.company && detail.description && detail.working_time,
    'Advertisement details must contain company, description and working_time'
  );

  await req('/api/ads/999999', 404);
  await req('/api/ads/abc', 404);
  await req('/api/ads', 405, 'DELETE');

  // 3. Les modifications sans jeton CSRF doivent être refusées.
  const noCsrf = await fetch(base + '/api/ads', {
    method: 'POST',
    headers: {
      Cookie: cookie,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({}),
  });

  assert.equal(noCsrf.status, 403, 'POST without CSRF token must be rejected');
  checks++;

  // 4. Validation des données.
  await req('/api/ads', 422, 'POST', {});

  await req('/api/ads', 422, 'POST', {
    title: 'x',
    short_description: 'x',
    description: 'x',
    location: 'Paris',
    working_time: 'CDI',
    company_id: 99999,
    category_id: 1,
  });

  // 5. Création d'une annonce de test.
  const valid = {
    title: 'Smoke test',
    short_description: 'Test',
    description: 'Test description',
    location: 'Nantes',
    working_time: 'CDI - Temps plein',
    company_id: 1,
    category_id: 1,
  };

  const ad = await req('/api/ads', 201, 'POST', valid);

  assert.ok(Number.isInteger(ad.id), 'Created advertisement must have an ID');

  try {
    await req(`/api/ads/${ad.id}`, 200);

    // Une catégorie est obligatoire : null doit être rejeté.
    await req(`/api/ads/${ad.id}`, 422, 'PATCH', {
      category_id: null,
    });

    const updated = await req(`/api/ads/${ad.id}`, 200, 'PATCH', {
      salary: '35000 EUR/year',
    });

    assert.equal(updated.salary, '35000 EUR/year');

    const replaced = await req(`/api/ads/${ad.id}`, 200, 'PUT', {
      ...valid,
      title: 'Replacement',
    });

    assert.equal(replaced.title, 'Replacement');

    await req(`/api/ads/${ad.id}`, 422, 'PATCH', {
      source: 'LOCAL',
    });
  } finally {
    await req(`/api/ads/${ad.id}`, 204, 'DELETE');
  }

  await req(`/api/ads/${ad.id}`, 404);

  // 6. Corps HTTP mal formés ou non autorisés.
  const bad = await fetch(base + '/api/ads', {
    method: 'POST',
    headers: {
      Cookie: cookie,
      'X-CSRF-Token': csrfToken,
      'Content-Type': 'application/json',
    },
    body: '{',
  });

  assert.equal(bad.status, 400, 'Invalid JSON must be rejected');
  checks++;

  const media = await fetch(base + '/api/ads', {
    method: 'POST',
    headers: {
      Cookie: cookie,
      'X-CSRF-Token': csrfToken,
      'Content-Type': 'text/plain',
    },
    body: 'hello',
  });

  assert.equal(media.status, 415, 'Unsupported media type must be rejected');
  checks++;

  const huge = await fetch(base + '/api/ads', {
    method: 'POST',
    headers: {
      Cookie: cookie,
      'X-CSRF-Token': csrfToken,
      'Content-Type': 'application/json',
    },
    body: 'x'.repeat(65537),
  });

  assert.equal(huge.status, 413, 'Oversized body must be rejected');
  checks++;

  // 7. Fichiers privés inaccessibles.
  for (const path of [
    '/.env',
    '/database/schema.sql',
    '/api/config/database.php',
  ]) {
    const response = await fetch(base + path);
    assert.equal(response.status, 404, `${path} must not be accessible`);
    checks++;
  }

  const index = await fetch(base + '/');
  assert.equal(index.status, 200);
  assert.ok((await index.text()).includes('AlternaTech'));
  checks++;

  console.log(
    `PASS ${checks} HTTP checks; session authentication; CSRF; CRUD; validation; private files inaccessible`
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});