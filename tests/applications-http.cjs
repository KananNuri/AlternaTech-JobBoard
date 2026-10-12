const assert = require('node:assert/strict');

const base = process.env.TEST_API_URL || 'http://127.0.0.1:8080';

function createClient() {
  let cookie = '';
  let csrfToken = '';

  async function request(path, expectedStatus, options = {}) {
    const headers = new Headers(options.headers || {});

    if (cookie) {
      headers.set('Cookie', cookie);
    }

    const response = await fetch(base + path, {
      ...options,
      headers,
      redirect: 'manual',
    });

    const setCookie = response.headers.get('set-cookie');

    if (setCookie) {
      cookie = setCookie.split(';')[0];
    }

    const text = await response.text();

    assert.equal(
      response.status,
      expectedStatus,
      `${options.method || 'GET'} ${path}: ${text}`
    );

    return text ? JSON.parse(text) : null;
  }

  return {
    request,

    async login(email, password) {
      const result = await request('/api/auth/login', 200, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });

      assert.equal(typeof result.csrf_token, 'string');
      assert.ok(result.csrf_token.length > 0);

      csrfToken = result.csrf_token;
      return result;
    },

    csrfToken() {
      return csrfToken;
    },
  };
}

(async () => {
  // 1. Les routes de consultation exigent une session.
  await createClient().request('/api/applications/mine', 401);
  await createClient().request('/api/companies/mine/applications', 401);

  // 2. Les méthodes non autorisées doivent être refusées.
  await createClient().request('/api/applications/mine', 405, {
    method: 'DELETE',
  });

  await createClient().request('/api/companies/mine/applications', 405, {
    method: 'POST',
  });

  // 3. Une connexion invalide doit être refusée.
  await createClient().request('/api/auth/login', 401, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'invalid@example.invalid',
      password: 'wrong-password',
    }),
  });

  // 4. Les identifiants de test doivent être disponibles.
  const aliceEmail = process.env.TEST_RECRUITER_EMAIL;
  const alicePassword = process.env.TEST_RECRUITER_PASSWORD;
  const bobEmail = process.env.TEST_USER_EMAIL;
  const bobPassword = process.env.TEST_USER_PASSWORD;

  assert.ok(aliceEmail && alicePassword, 'Alice test credentials are required');
  assert.ok(bobEmail && bobPassword, 'Bob test credentials are required');

  // 5. Alice et Bob utilisent des sessions distinctes.
  const alice = createClient();
  const bob = createClient();

  const aliceLogin = await alice.login(aliceEmail, alicePassword);
  const bobLogin = await bob.login(bobEmail, bobPassword);

  assert.equal(aliceLogin.user.id, 1, 'Alice test account expected');
  assert.equal(bobLogin.user.id, 3, 'Bob test account expected');

  // 6. Alice, recruteuse de TechNova, voit ses candidatures.
  const aliceResult = await alice.request(
    '/api/companies/mine/applications',
    200
  );

  assert.ok(Array.isArray(aliceResult.applications));
  assert.ok(
    aliceResult.applications.length > 0,
    'Alice should see TechNova applications'
  );

  const application = aliceResult.applications.find(
    item => item.application_id === 3
  );

  assert.ok(application, 'Expected test application 3');
  assert.equal(application.company, 'TechNova');

  // 7. Bob ne doit pas pouvoir modifier la candidature d'Alice.
  await bob.request('/api/companies/mine/applications', 200);

  await bob.request('/api/companies/mine/applications', 404, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': bob.csrfToken(),
    },
    body: JSON.stringify({
      application_id: 3,
      status: 'accepted',
    }),
  });

  // 8. Sans jeton CSRF, la modification doit être refusée.
  await alice.request('/api/companies/mine/applications', 403, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      application_id: 3,
      status: 'reviewed',
    }),
  });

  // 9. Alice voit sa candidature, mais Bob ne la voit pas.
  const aliceApplications = await alice.request('/api/applications/mine', 200);
  const bobApplications = await bob.request('/api/applications/mine', 200);

  assert.ok(Array.isArray(aliceApplications.applications));
  assert.ok(Array.isArray(bobApplications.applications));

  assert.ok(
    aliceApplications.applications.some(item => item.id === 3),
    'Alice should see her own test application'
  );

  assert.ok(
    bobApplications.applications.every(item => item.id !== 3),
    'Bob must not see Alice’s test application'
  );

  // 10. Bob peut postuler une fois à l'annonce 2, mais pas deux fois.
  const advertisementId = 2;
  const message = 'Candidature de test automatisé';

  assert.ok(
    !bobApplications.applications.some(
      item => item.advertisement_id === advertisementId
    ),
    'Bob must not already have applied to advertisement 2'
  );

  const createApplication = () => bob.request('/api/applications/mine', 201, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': bob.csrfToken(),
    },
    body: JSON.stringify({
      advertisement_id: advertisementId,
      message,
    }),
  });

  const created = await createApplication();

  assert.equal(created.person_id, 3);
  assert.equal(created.advertisement_id, advertisementId);
  assert.equal(created.message, message);
  assert.equal(created.status, 'pending');

  await bob.request('/api/applications/mine', 409, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': bob.csrfToken(),
    },
    body: JSON.stringify({
      advertisement_id: advertisementId,
      message,
    }),
  });

  console.log(
    'PASS application HTTP checks: authentication, sessions, recruiter permissions, CSRF, candidate isolation, application creation and duplicate rejection'
  );
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});