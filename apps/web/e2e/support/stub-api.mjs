// A stand-in for the Laravel API, used only by Playwright (SPRINT_03 Phase 0).
//
// The web app's server components and BFF handlers call `/api/v1` from the Next process, which means
// browser-level request interception cannot reach them — a spec stubbing `page.route()` would still hit
// a real backend for everything rendered on the server. So the upstream itself is replaced: Next is
// started with its API base URL pointed here.
//
// The alternative was standing up Postgres and Laravel for every e2e run. That buys realism the unit and
// feature suites already provide — the backend has 341 tests of its own — at the cost of the two things
// end-to-end tests most need, speed and determinism. What is genuinely only testable here is the
// browser-side assembly: server components, BFF round trips, hydration, i18n direction, theming.
//
// Fixtures are plain objects keyed by "METHOD /path". Anything unmatched returns a coded 404 in the real
// envelope shape, so a spec that drifts onto an unstubbed endpoint fails loudly instead of hanging.

import { createServer } from 'node:http';

const PORT = Number(process.env.STUB_API_PORT ?? 8099);

/** The standard success envelope, so the SDK parses these exactly as it parses Laravel's. */
const ok = (data, meta = {}) => ({ success: true, data, meta });

const FIXTURES = {
  'GET /api/v1/health': ok({ status: 'ok' }),

  'GET /api/v1/auth/me': ok({
    user: {
      id: 1,
      uuid: '11111111-1111-4111-8111-111111111111',
      name: 'E2E User',
      email: 'e2e@qayd.test',
      locale: 'en',
      email_verified: true,
      mfa_enrolled: false,
    },
    active_company: {
      uuid: '22222222-2222-4222-8222-222222222222',
      name_en: 'E2E Trading Co',
      name_ar: 'شركة تجريبية',
      base_currency: 'KWD',
    },
    companies: [
      {
        uuid: '22222222-2222-4222-8222-222222222222',
        name_en: 'E2E Trading Co',
        name_ar: 'شركة تجريبية',
        role: 'owner',
      },
    ],
    permissions: ['accounting.journal.read', 'accounting.trial_balance.read'],
  }),
};

const server = createServer((req, res) => {
  const path = (req.url ?? '/').split('?')[0];
  const key = `${req.method} ${path}`;
  const body = FIXTURES[key];

  res.setHeader('content-type', 'application/json');

  if (body === undefined) {
    // Loud, not silent: an unstubbed endpoint means a spec has outgrown its fixtures.
    res.statusCode = 404;
    res.end(
      JSON.stringify({
        success: false,
        code: 'NOT_FOUND',
        message: `No e2e fixture for ${key}. Add one in e2e/support/stub-api.mjs.`,
      }),
    );
    return;
  }

  res.statusCode = 200;
  res.end(JSON.stringify(body));
});

server.listen(PORT, '127.0.0.1', () => {
  process.stdout.write(`e2e stub API listening on http://127.0.0.1:${PORT}\n`);
});
