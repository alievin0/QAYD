# End-to-End Tests — QAYD Testing
Version: 1.0
Status: Design Specification
Module: Testing
Submodule: E2E_TESTS
---

# Purpose

This document specifies how QAYD proves that its critical user journeys work — end to end, in a
real browser, against a real API — before a change reaches a customer's ledger. Where the
[`../api/API_TESTING.md`](../api/API_TESTING.md) pyramid owns the layers below the browser (unit,
feature, contract) and the [`../frontend/README.md`](../frontend/README.md) `# Quality gates`
section names `npm run test:e2e` as one of the five required checks, this document owns the E2E
layer itself: which journeys are covered, how each maps to the `docs/frontend/flows/` specification
that authored it, how a covered screen is verified in all four theme/direction variants, how test
tenants and users are seeded, how authentication and network are handled without hitting a real
identity provider, what artifacts a failed run produces, how a flaky test is quarantined rather than
retried into the ground, and how CI invokes the whole thing.

An E2E test in QAYD is not a coverage number. It is a standing assertion that a person can sign in,
that an accountant can post a balanced journal entry, that a sales user can issue an invoice, that a
finance manager can drive a bank reconciliation to zero variance and close it, that a CFO can lock a
period, and that a reviewer can approve or reject an AI proposal — each with the exact permission
gates, the exact envelope, and the exact human-in-the-loop affordances the flow docs specify. A
regression in any of those is not cosmetic; it is a company unable to close its books, or an AI
proposal that auto-committed when a human should have decided. The E2E suite exists to fail loudly,
in CI, when one of those journeys breaks.

Three platform constraints, inherited from [`../frontend/README.md`](../frontend/README.md) and
restated once for this layer because every spec below enforces them:

1. **The frontend computes no financial truth, so E2E never asserts a client-side calculation as the
   source of truth.** A test reads the total the server persisted (after `POST`/post), never the
   cosmetic Live Totals preview, as the authoritative figure. Asserting the preview would encode a
   value the platform explicitly makes non-authoritative.
2. **AI is visible, never silent — and never auto-commits a sensitive action.** Every journey that
   surfaces an AI proposal asserts the confidence badge, the reasoning affordance, and the
   approve/reject controls are present, and asserts that no money-moving action fired without a human
   decision. "Do it" never renders on an approval journey (see
   [`../frontend/flows/APPROVAL_FLOW.md`](../frontend/flows/APPROVAL_FLOW.md)).
3. **RBAC is server-authoritative; E2E asserts both the courtesy (a control hidden/disabled for a
   role without the permission) and the enforcement (the API's `403` when the check is bypassed).**

# Scope

## In scope

- The seven critical user journeys enumerated in `# What to Test / Coverage`, each cross-linked to
  its authoritative flow spec under [`../frontend/flows/`](../frontend/flows/).
- The four-variant visual-regression rule (light/LTR, light/RTL, dark/LTR, dark/RTL) for every
  screen a covered journey lands on, per [`../frontend/README.md`](../frontend/README.md)'s Testing
  row.
- Test-user and test-tenant seeding, so a journey runs against a known company with known
  permissions, chart of accounts, bank feed, and AI settings.
- Network and authentication handling: Sanctum cookie session priming, `X-Company-Id` scoping,
  deterministic AI responses, realtime (Reverb) event handling.
- Artifacts (screenshots, video, trace), flaky-test quarantine, and CI invocation.

## Out of scope (owned elsewhere)

- Unit and component tests (Vitest + Testing Library) — colocated per feature, owned by the frontend
  repo and [`../api/API_TESTING.md`](../api/API_TESTING.md)'s lower pyramid layers.
- API feature tests, tenant-isolation feature tests, and OpenAPI contract tests (Pest/PHPUnit,
  Spectator/Schemathesis) — owned by [`../api/API_TESTING.md`](../api/API_TESTING.md) and
  [`./SECURITY_TESTS.md`](./SECURITY_TESTS.md).
- Load and performance testing (k6) — owned by [`./LOAD_TESTS.md`](./LOAD_TESTS.md).
- AI-quality evaluation (accuracy, calibration, hallucination) — owned by
  [`./AI_EVALUATION.md`](./AI_EVALUATION.md). E2E asserts the AI *surfaces* correctly (badge,
  reasoning, gate); it does not grade the AI's answer.
- The Flutter mobile app's `integration_test` journeys — a separate surface with its own harness;
  this document is the **web** E2E specification only.

# Tooling

| Concern | Tool | Detail |
|---|---|---|
| E2E runner | Playwright (`@playwright/test`) | Chromium is the required gating browser; WebKit and Firefox run nightly, not per-PR. One `playwright.config.ts` at `frontend/`; specs under `frontend/tests/e2e/`. |
| Assertions | Playwright `expect` (web-first, auto-retrying) | Web-first assertions (`toBeVisible`, `toHaveText`) auto-wait; no hand-rolled `waitForTimeout` — a fixed sleep is a lint failure in this suite. |
| Visual regression | Playwright `toHaveScreenshot()` | Four variants per covered screen; baselines committed under `tests/e2e/__screenshots__/`; masked regions for volatile content (live clock, relative timestamps). |
| Selectors | `getByRole` / `getByLabel` / `data-testid` | Role and label queries first (they double as accessibility assertions); `data-testid` only where semantics are genuinely ambiguous. Never a brittle CSS/nth-child chain. |
| Test data | API-driven seeding via a fixtures endpoint + Laravel factories | A test-only `POST /api/v1/testing/seed` (enabled solely when `APP_ENV=testing`) provisions a tenant; see `# Conventions & Structure`. |
| Auth | `storageState` per role, primed once in global setup | Session cookies captured once per test role and reused; individual specs do not re-drive `/login` unless the login journey *is* the subject. |
| Network control | Playwright `page.route` for the AI engine + deterministic seed data | Deterministic AI responses via fixture interception; all other `/api/v1` traffic hits the real Laravel test API. |
| Realtime | Reverb test server (or a fake Echo transport) | A journey that depends on a `.approvals`/`.dashboard` push either runs against a real Reverb test instance or injects the event via the fake transport; never a blind sleep hoping the socket delivered. |
| CI | GitHub Actions, sharded | `npm run test:e2e`, matching the frontend `# Quality gates`; sharded across runners, artifacts uploaded on failure. |

Playwright is the fixed choice, aligned with [`../api/API_TESTING.md`](../api/API_TESTING.md)'s
pyramid (Playwright owns the E2E tier; k6 owns performance). A change to the runner is an
architectural decision recorded here and in [`../frontend/README.md`](../frontend/README.md), not a
per-PR choice.

# Conventions & Structure

## Folder layout

Specs live in `frontend/tests/e2e/`, one file per journey, named for the journey not the screen, so
the file name reads as the guarantee it makes:

```text
frontend/
├── playwright.config.ts
└── tests/
    └── e2e/
        ├── fixtures/
        │   ├── qayd-fixtures.ts        # extends `test` with authed roles, seeded tenant, i18n/theme
        │   ├── seed.ts                 # calls POST /api/v1/testing/seed, returns handles
        │   └── ai-fixtures.ts          # deterministic AI proposal/insight payloads
        ├── auth/
        │   └── login-mfa.spec.ts       # LOGIN_FLOW
        ├── onboarding/
        │   └── create-company.spec.ts  # ONBOARDING_FLOW
        ├── accounting/
        │   ├── post-journal-entry.spec.ts   # JOURNAL_ENTRIES + create-invoice's posted-JE tail
        │   └── month-end-close.spec.ts      # MONTH_END_CLOSE_FLOW
        ├── sales/
        │   └── create-issue-invoice.spec.ts # CREATE_INVOICE_FLOW
        ├── banking/
        │   └── reconciliation-close.spec.ts # BANK_RECONCILIATION_FLOW
        ├── ai/
        │   └── approve-reject-proposal.spec.ts # APPROVAL_FLOW
        ├── visual/
        │   └── covered-screens.spec.ts  # the four-variant screenshot sweep
        ├── __screenshots__/            # committed baselines, per variant
        └── global-setup.ts             # seeds the base tenant, primes storageState per role
```

## Naming

| Thing | Convention | Example |
|---|---|---|
| Spec file | kebab-case journey name + `.spec.ts` | `reconciliation-close.spec.ts` |
| `test.describe` block | The journey title from the flow doc | `"Bank reconciliation → close (BANK_RECONCILIATION_FLOW)"` |
| `test` title | A single asserted outcome, present tense | `"posts a balanced journal entry and lands it in the ledger"` |
| Screenshot name | `<screen>.<theme>-<dir>.png` | `journal-entries.dark-rtl.png` |
| Test role handle | The RBAC role slug | `accountant`, `financeManager`, `salesEmployee`, `auditor` |
| Seed handle | camelCase noun | `seed.company`, `seed.bankAccount`, `seed.aiProposal` |

## The QAYD test fixture

Every spec imports the extended `test` from `fixtures/qayd-fixtures.ts`, never the bare Playwright
`test`. The fixture supplies: an authenticated page per role (via `storageState`), the seeded tenant
handles, and helpers for switching theme (`data-theme`) and direction (`dir`) so a journey and its
visual variants share one setup.

```ts
// tests/e2e/fixtures/qayd-fixtures.ts
import { test as base, expect, type Page } from '@playwright/test';
import { seedTenant, type SeededTenant } from './seed';

type Role = 'owner' | 'financeManager' | 'accountant' | 'salesEmployee' | 'auditor' | 'readOnly';

type QaydFixtures = {
  seed: SeededTenant;
  as: (role: Role) => Promise<Page>;               // a page carrying that role's storageState + X-Company-Id
  setTheme: (page: Page, theme: 'light' | 'dark') => Promise<void>;
  setLocale: (page: Page, locale: 'en' | 'ar') => Promise<void>;
};

export const test = base.extend<QaydFixtures>({
  seed: async ({ request }, use) => {
    const tenant = await seedTenant(request);       // POST /api/v1/testing/seed (testing env only)
    await use(tenant);
    await tenant.teardown();                         // deletes the ephemeral company after the test
  },
  as: async ({ browser, seed }, use) => {
    await use(async (role) => {
      const context = await browser.newContext({
        storageState: `tests/e2e/.auth/${role}.json`, // primed in global-setup
        extraHTTPHeaders: { 'X-Company-Id': seed.company.id },
      });
      return context.newPage();
    });
  },
  setTheme: async ({}, use) => {
    await use(async (page, theme) => {
      await page.emulateMedia({ colorScheme: theme });
      await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
    });
  },
  setLocale: async ({}, use) => {
    await use(async (page, locale) => {
      // locale is a cookie the middleware resolves; flip it and reload so dir/lang re-derive
      await page.context().addCookies([{ name: 'qayd_locale', value: locale, url: page.url() }]);
      await page.reload();
      await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
    });
  },
});

export { expect };
```

## Test-user and test-tenant seeding

Every journey runs against a freshly provisioned, isolated company so runs never contend for shared
state and a leaked row can never cross into another test. Seeding is API-driven — the same envelope
and the same `BelongsToCompany`/RLS discipline the product uses — never a raw SQL insert that would
bypass the invariants under test.

```ts
// tests/e2e/fixtures/seed.ts
import type { APIRequestContext } from '@playwright/test';

export type SeededTenant = {
  company: { id: string; nameEn: string; nameAr: string; baseCurrency: 'KWD' };
  users: Record<'owner' | 'financeManager' | 'accountant' | 'salesEmployee' | 'auditor' | 'readOnly',
                { id: string; email: string; permissions: string[] }>;
  bankAccount: { id: string; iban: string };
  customer: { id: string };
  product: { id: string; unitPrice: string };
  fiscalPeriod: { id: string; status: 'open' };
  aiProposal: { id: string; confidence: number };  // a pre-created ai_decisions row for the AI journey
  teardown: () => Promise<void>;
};

export async function seedTenant(request: APIRequestContext): Promise<SeededTenant> {
  // Enabled ONLY when APP_ENV=testing; the route 404s in every other environment.
  const res = await request.post('/api/v1/testing/seed', {
    data: {
      preset: 'kuwait-sme',            // KWD, Arabic + English, IFRS COA, one bank feed, VAT-pending
      seed_users: ['owner', 'finance_manager', 'accountant', 'sales_employee', 'auditor', 'read_only'],
      seed_bank_feed: true,            // 200+ statement lines for the reconciliation journey
      seed_ai_proposal: { confidence_score: 78, subject_type: 'journal_entry' },
    },
  });
  const { data } = await res.json();   // standard envelope
  return { ...data, teardown: async () => { await request.delete(`/api/v1/testing/seed/${data.company.id}`); } };
}
```

The `kuwait-sme` preset is the canonical E2E tenant: base currency KWD, English + Arabic locales, an
IFRS-aligned chart of accounts, one connected bank feed with a fixture statement, the fifteen-agent
roster at platform-default autonomy, and one open fiscal period. Every seeded user carries exactly
the permission set its role grants (per [`../foundation/PERMISSION_SYSTEM.md`](../foundation/PERMISSION_SYSTEM.md)),
so a permission assertion in a spec is asserting the real grant, not a test convenience.

# Patterns

## The Page-Object seam

Each journey composes small page objects (one per screen it traverses), so a selector change touches
one file, not every spec. Page objects expose intent methods (`postEntry()`, `matchLine()`), never
raw locators, and they hold no assertions — the spec asserts, the page object acts.

```ts
// tests/e2e/pages/journal-entry.page.ts
import { type Page, type Locator, expect } from '@playwright/test';

export class JournalEntryComposer {
  constructor(private readonly page: Page) {}

  readonly balanceIndicator = (): Locator => this.page.getByTestId('je-balance-indicator');

  async addLine(account: string, debit: string, credit: string) {
    const row = this.page.getByRole('row', { name: /new line/i });
    await row.getByLabel('Account').fill(account);
    await this.page.getByRole('option', { name: new RegExp(account) }).click();
    if (debit !== '0') await row.getByLabel('Debit').fill(debit);
    if (credit !== '0') await row.getByLabel('Credit').fill(credit);
  }

  // The Post button is permission-gated; a spec asserts its presence/absence, this only clicks it.
  async post() {
    await this.page.getByRole('button', { name: /post entry/i }).click();
  }
}
```

## Authentication: prime once, reuse everywhere

`global-setup.ts` signs each role in once through the real `/login` (plus MFA where the role
mandates it) and writes the resulting cookie session to `tests/e2e/.auth/<role>.json`. Individual
specs load that `storageState` instead of re-driving the login form — the login journey is exercised
exactly once, by its own spec, and never re-paid for as setup elsewhere.

```ts
// tests/e2e/global-setup.ts (excerpt)
import { chromium, request } from '@playwright/test';
import { primeRoleSession } from './fixtures/auth';

export default async function globalSetup() {
  const api = await request.newContext();
  await api.post('/api/v1/testing/seed', { data: { preset: 'kuwait-sme', /* base tenant */ } });
  const browser = await chromium.launch();
  for (const role of ['owner', 'financeManager', 'accountant', 'salesEmployee', 'auditor', 'readOnly'] as const) {
    await primeRoleSession(browser, role); // drives /login (+ /mfa for financial roles), saves storageState
  }
  await browser.close();
}
```

## Network handling: real API, deterministic AI

The suite runs against the real Laravel test API (a bug caught here is a bug caught for every
client), but the FastAPI AI engine is intercepted so a proposal's confidence, reasoning, and sources
are deterministic — an E2E run must never flake because a live model returned a slightly different
number. The engine's *behavior contract* (does the badge render, is the gate correct) is what E2E
asserts; its answer *quality* is [`./AI_EVALUATION.md`](./AI_EVALUATION.md)'s job.

```ts
// Deterministic AI proposal for the approval journey
await page.route('**/api/v1/ai/proposals/**', async (route) => {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      success: true,
      data: {
        id: 'aid_test_001',
        agent_code: 'GENERAL_ACCOUNTANT',
        confidence_score: 78,                 // Medium band → gated, no one-click "Do it"
        reasoning: 'Matched 12 prior bills from this vendor to account 5130 …',
        sources: [{ type: 'attachment', id: 88123, label: 'bill_scan.pdf' }],
        can_execute_directly: false,
      },
      message: 'ok', errors: [], meta: { pagination: null },
      request_id: 'req_test', timestamp: '2026-07-16T09:00:00Z',
    }),
  });
});
```

## Realtime

A journey that depends on a pushed event (a held approval landing in the queue, a dashboard KPI
tick) subscribes through the real Reverb test server, or injects the event via a fake Echo transport
exposed on `window.__qaydTestEcho`. Either way the spec waits on the resulting DOM change with a
web-first assertion, never a timer.

```ts
await page.evaluate(() =>
  window.__qaydTestEcho.emit('private-company.CID.approvals', '.approval.status_changed',
    { id: 'apr_1', status: 'held' }));
await expect(page.getByRole('listitem', { name: /vendor payment run/i })).toHaveClass(/held/);
```

## The four-variant visual-regression rule

Every screen a covered journey lands on is screenshotted in all four combinations of theme × direction —
`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL` — per [`../frontend/README.md`](../frontend/README.md).
This is not four cosmetic snapshots; it is the enforcement that dark mode is a token remap (not an
inverted filter) and that RTL mirrors via logical properties (not a parallel component tree). A
`data-testid` region containing a live clock or a relative timestamp is masked so a legitimate
time change is never a false diff.

```ts
// tests/e2e/visual/covered-screens.spec.ts
import { test, expect } from '../fixtures/qayd-fixtures';

const VARIANTS = [
  { theme: 'light', locale: 'en', dir: 'ltr' },
  { theme: 'light', locale: 'ar', dir: 'rtl' },
  { theme: 'dark',  locale: 'en', dir: 'ltr' },
  { theme: 'dark',  locale: 'ar', dir: 'rtl' },
] as const;

const SCREENS = [
  { name: 'journal-entries', path: '/accounting/journal-entries', role: 'accountant' },
  { name: 'trial-balance',   path: '/accounting/trial-balance',   role: 'accountant' },
  { name: 'reconciliation',  path: '/banking/BANK/reconciliation', role: 'financeManager' },
  { name: 'approvals',       path: '/approvals',                   role: 'financeManager' },
  { name: 'invoice-builder', path: '/sales/invoices/new',          role: 'salesEmployee' },
] as const;

for (const screen of SCREENS) {
  for (const v of VARIANTS) {
    test(`${screen.name} renders correctly · ${v.theme}/${v.dir}`, async ({ as, setTheme, setLocale }) => {
      const page = await as(screen.role as never);
      await setLocale(page, v.locale);
      await setTheme(page, v.theme);
      await page.goto(screen.path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible(); // real content, not a spinner
      await expect(page).toHaveScreenshot(`${screen.name}.${v.theme}-${v.dir}.png`, {
        mask: [page.getByTestId('live-clock'), page.getByTestId('relative-timestamp')],
        maxDiffPixelRatio: 0.01,
      });
    });
  }
}
```

# What to Test / Coverage

The seven critical journeys, each mapped to its authoritative flow spec. A journey is "covered" when
its happy path, its primary permission boundary, and its primary failure branch are asserted, and
every screen it lands on carries the four visual variants.

| # | Journey | Flow spec | Key assertions |
|---|---|---|---|
| 1 | Login + MFA | [`../frontend/flows/LOGIN_FLOW.md`](../frontend/flows/LOGIN_FLOW.md) | credentials → MFA → resolved home; lockout countdown; invalid-credential anti-enumeration; no AI surface anywhere in `(auth)` |
| 2 | Onboarding (create company) | [`../frontend/flows/ONBOARDING_FLOW.md`](../frontend/flows/ONBOARDING_FLOW.md) | first-run wizard → operational company; AI defaults are proposals a human keeps/changes, never pre-committed |
| 3 | Create + post journal entry | [`../frontend/flows/CREATE_INVOICE_FLOW.md`](../frontend/flows/CREATE_INVOICE_FLOW.md) (posted-JE tail) + `JOURNAL_ENTRIES.md` | live debit=credit indicator; Post gated on `accounting.journal.post`; posted entry is immutable |
| 4 | Create + issue invoice | [`../frontend/flows/CREATE_INVOICE_FLOW.md`](../frontend/flows/CREATE_INVOICE_FLOW.md) | server-authoritative totals; draft→post split; AI draft never issued in one motion |
| 5 | Bank reconciliation → close | [`../frontend/flows/BANK_RECONCILIATION_FLOW.md`](../frontend/flows/BANK_RECONCILIATION_FLOW.md) | AI auto-match sweep; human works unmatched queue; variance → 0; close gated on `bank.reconcile.close` |
| 6 | Month-end close | [`../frontend/flows/MONTH_END_CLOSE_FLOW.md`](../frontend/flows/MONTH_END_CLOSE_FLOW.md) | checklist gates; trial-balance approval; period lock gated, human-only |
| 7 | AI proposal approve/reject | [`../frontend/flows/APPROVAL_FLOW.md`](../frontend/flows/APPROVAL_FLOW.md) | confidence + reasoning + sources render; Approve/Reject/Delegate only; "Do it" never renders; reject reason mandatory |

## Journey 3 — post a balanced journal entry (worked spec)

```ts
// tests/e2e/accounting/post-journal-entry.spec.ts
import { test, expect } from '../fixtures/qayd-fixtures';
import { JournalEntryComposer } from '../pages/journal-entry.page';

test.describe('Post journal entry (JOURNAL_ENTRIES)', () => {
  test('an accountant posts a balanced entry and it lands immutable in the ledger', async ({ as }) => {
    const page = await as('accountant');
    await page.goto('/accounting/journal-entries/new');
    const je = new JournalEntryComposer(page);

    await je.addLine('5130', '186.500', '0');
    await je.addLine('2110', '0', '186.500');
    await expect(je.balanceIndicator()).toHaveText(/balanced/i);   // client convenience, not the source of truth

    await je.post();
    // authoritative fact: the server persisted it and reports it posted
    await expect(page.getByText(/JE-2026-07-\d+/)).toBeVisible();
    await expect(page.getByTestId('je-status')).toHaveText(/posted/i);

    // immutability: a posted entry offers Reverse, never Edit/Delete
    await expect(page.getByRole('button', { name: /^edit/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /reverse/i })).toBeVisible();
  });

  test('the Post control is absent for a role without accounting.journal.post', async ({ as }) => {
    const page = await as('salesEmployee');
    await page.goto('/accounting/journal-entries/new');
    await expect(page.getByRole('button', { name: /post entry/i })).toHaveCount(0); // hidden, not just disabled
  });

  test('an unbalanced entry cannot be posted (server 422 surfaced inline)', async ({ as }) => {
    const page = await as('accountant');
    await page.goto('/accounting/journal-entries/new');
    const je = new JournalEntryComposer(page);
    await je.addLine('5130', '100.000', '0');
    await je.addLine('2110', '0', '90.000');            // deliberately unbalanced
    await je.post();
    await expect(page.getByRole('alert')).toContainText(/balance/i); // 422 balance_mismatch, surfaced
    await expect(page.getByTestId('je-status')).not.toHaveText(/posted/i);
  });
});
```

## Journey 5 — reconciliation close (worked spec)

```ts
// tests/e2e/banking/reconciliation-close.spec.ts
import { test, expect } from '../fixtures/qayd-fixtures';

test.describe('Bank reconciliation → close (BANK_RECONCILIATION_FLOW)', () => {
  test('a finance manager drives variance to zero and closes the period', async ({ as, seed }) => {
    const page = await as('financeManager');
    await page.goto(`/banking/${seed.bankAccount.id}/reconciliation`);

    // AI sweep already auto-committed the confident matches; the human works the exception queue
    await expect(page.getByTestId('unmatched-count')).not.toHaveText('0');
    const firstSuggestion = page.getByTestId('match-suggestion').first();
    await expect(firstSuggestion.getByTestId('confidence-badge')).toBeVisible(); // AI is visible
    await firstSuggestion.getByRole('button', { name: /accept match/i }).click();

    // create the missing bank-fee entry so the variance closes to zero
    await page.getByRole('button', { name: /create adjustment/i }).click();
    await page.getByLabel('Account').fill('5210'); // bank charges
    await page.getByRole('button', { name: /post adjustment/i }).click();
    await expect(page.getByTestId('reconciliation-variance')).toHaveText(/0\.000/);

    await page.getByRole('button', { name: /close reconciliation/i }).click();
    await page.getByRole('button', { name: /confirm close/i }).click();
    await expect(page.getByTestId('reconciliation-status')).toHaveText(/confirmed|closed/i);
  });

  test('the AI service account can never close — Close is human-gated', async ({ as }) => {
    const page = await as('accountant'); // holds bank.reconcile but NOT bank.reconcile.close
    await page.goto('/banking/BANK/reconciliation');
    await expect(page.getByRole('button', { name: /close reconciliation/i })).toBeDisabled();
  });
});
```

## Journey 7 — AI proposal approve/reject (worked spec)

```ts
// tests/e2e/ai/approve-reject-proposal.spec.ts
import { test, expect } from '../fixtures/qayd-fixtures';

test.describe('AI proposal approve/reject (APPROVAL_FLOW)', () => {
  test('a reviewer sees confidence + reasoning and only Approve/Reject/Delegate — never Do it', async ({ as, seed }) => {
    const page = await as('financeManager');
    await page.goto(`/approvals/${seed.aiProposal.id}`);

    await expect(page.getByTestId('confidence-badge')).toBeVisible();
    await page.getByRole('button', { name: /show reasoning/i }).click();
    await expect(page.getByTestId('reasoning-panel')).toBeVisible();
    await expect(page.getByRole('link', { name: /#\d+/ })).toBeVisible(); // a real source citation

    await expect(page.getByRole('button', { name: /^do it/i })).toHaveCount(0);        // never here
    await expect(page.getByRole('button', { name: /^approve/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /^reject/i })).toBeVisible();
  });

  test('reject requires a mandatory reason before it can commit', async ({ as, seed }) => {
    const page = await as('financeManager');
    await page.goto(`/approvals/${seed.aiProposal.id}`);
    await page.getByRole('button', { name: /^reject/i }).click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog.getByRole('button', { name: /confirm reject/i })).toBeDisabled();
    await dialog.getByLabel(/reason/i).fill('Vendor bank details unverified');
    await expect(dialog.getByRole('button', { name: /confirm reject/i })).toBeEnabled();
  });
});
```

# CI Integration

## Invocation

The gating command is exactly the one [`../frontend/README.md`](../frontend/README.md) `# Quality
gates` names:

```bash
npm run test:e2e          # playwright test — the same invocation CI uses
npm run test:e2e -- --update-snapshots   # regenerate visual baselines (never run in CI; a reviewer runs it deliberately)
```

## Pipeline shape

```yaml
# .github/workflows/e2e.yml (excerpt)
jobs:
  e2e:
    strategy:
      fail-fast: false
      matrix:
        shard: [1/4, 2/4, 3/4, 4/4]   # Playwright native sharding across four runners
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - name: Boot the test API + Reverb + seed
        run: ./scripts/ci-boot-test-stack.sh   # Laravel test API (APP_ENV=testing), Reverb, base tenant seed
      - run: npm run test:e2e -- --shard=${{ matrix.shard }}
        env:
          PLAYWRIGHT_BASE_URL: http://localhost:3000
          NEXT_PUBLIC_API_BASE_URL: http://localhost:8080
      - name: Upload artifacts on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-artifacts-${{ strategy.job-index }}
          path: [ playwright-report/, test-results/ ]  # HTML report, videos, traces, diff images
```

## Gate policy

- **Required on every PR:** Chromium E2E + the four-variant visual sweep. A visual diff above
  `maxDiffPixelRatio` fails the PR; a reviewer either fixes the regression or, if the change is
  intended, regenerates baselines locally and commits them (baselines are never auto-updated in CI).
- **Nightly (not gating):** WebKit + Firefox runs, and the full journey set against the staging API.
- **Quarantined tests do not gate** (see below), but a quarantine older than 14 days fails a weekly
  audit job — a quarantine is a debt with a clock, not a graveyard.

## Artifacts

Every failed run uploads, per the config, a Playwright HTML report, the failing test's **video**, its
**trace** (openable in `npx playwright show-trace`), and any **visual diff** images (expected /
actual / diff). A reviewer never has to reproduce a CI failure locally to see what happened — the
trace replays the exact run, network and DOM included.

```ts
// playwright.config.ts (excerpt)
export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  forbidOnly: !!process.env.CI,           // a stray test.only fails CI
  retries: process.env.CI ? 1 : 0,        // one retry in CI; a test needing >1 is a quarantine candidate
  reporter: [['html'], ['github'], ['json', { outputFile: 'test-results/results.json' }]],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL,
    trace: 'on-first-retry',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'en-US',
    timezoneId: 'Asia/Kuwait',            // deterministic clock for the whole suite
  },
  expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.01 } },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
```

## Flaky-test quarantine

A test that fails then passes on its single CI retry is flagged flaky by the JSON reporter and, if it
recurs, is moved to quarantine rather than left to erode trust in the suite. Quarantine is explicit
and time-boxed:

- Tag the test `test.describe('… @quarantine', …)`; the CI matrix runs `@quarantine` in a
  **non-gating** job so it still runs (and its trace is still captured) but a failure does not block
  merge.
- A quarantined test carries a `// QUARANTINE: <owner> <date> <tracking-issue>` comment; the weekly
  audit fails if any quarantine is older than 14 days without a linked, open fix issue.
- A test is never "fixed" by adding a `waitForTimeout` or bumping the timeout — that hides the race,
  it does not resolve it. The fix is a web-first assertion on the real post-condition (a rendered
  status, a settled network response), which is why fixed sleeps are banned in this suite.

# Examples

The `# What to Test / Coverage` section carries the worked specs for journeys 3, 5, and 7 (the ones
whose assertions are least obvious). The remaining four follow the same shape; two short additional
illustrations:

**Login lockout (journey 1) — the countdown is the server's, never the client's:**

```ts
test('a locked account shows a live countdown driven by Retry-After, fields disabled', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) =>
    route.fulfill({ status: 429, headers: { 'Retry-After': '720' },
      body: JSON.stringify({ success: false, errors: [{ code: 'ACCOUNT_TEMPORARILY_LOCKED' }] }) }));
  await page.goto('/login');
  await page.getByLabel('Email').fill('cfo@al-noor.test');
  await page.getByLabel('Password').fill('wrong-password');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('alert')).toContainText(/too many/i);
  await expect(page.getByLabel('Email')).toBeDisabled();               // all fields disable, not just the button
  await expect(page.getByRole('link', { name: /forgot password/i })).toBeEnabled(); // the one enabled escape
});
```

**Invoice issue (journey 4) — the server total is authoritative, the preview is not:**

```ts
test('the posted invoice total is the server figure, not the Live Totals preview', async ({ as, seed }) => {
  const page = await as('accountant');
  await page.goto('/sales/invoices/new');
  await page.getByLabel('Customer').fill(seed.customer.id);
  await page.getByRole('button', { name: /add line/i }).click();
  await page.getByLabel('Product').fill(seed.product.id);
  await page.getByLabel('Quantity').fill('3');
  // the Live Totals Rail is cosmetic — we do not assert it as truth
  await page.getByRole('button', { name: /^post/i }).click();
  const persisted = page.getByTestId('invoice-total'); // rendered from the server's chk_invoice_totals-validated value
  await expect(persisted).toHaveText(/KWD/);
  await expect(page.getByTestId('invoice-status')).toHaveText(/issued|posted/i);
});
```

# Edge Cases

| Edge case | E2E handling |
|---|---|
| Live clock / relative timestamp in a screenshot | Masked via `data-testid` region + `timezoneId: 'Asia/Kuwait'` pinned in config; a real time change is never a false visual diff. |
| Western-Arabic numerals in the Arabic locale | The RTL visual variants assert ledger figures render Latin digits (`0123…`) even under `dir="rtl"` — a mixed numeral system in a ledger row is a defect the four-variant sweep catches, not a cosmetic nicety. |
| RTL mirroring vs. a parallel tree | The `.dark-rtl`/`.light-rtl` baselines are the enforcement that direction is a single root `dir` flip via logical properties; a component that hard-coded `left`/`right` diverges in exactly one variant, localizing the bug. |
| AI engine down (`503`) mid-journey | The reconciliation/approval specs intercept a `503` and assert the `AiUnavailable` fallback renders and the human path still completes — the journey degrades, it does not dead-end. |
| Realtime event never arrives | The fake Echo transport asserts on the resulting DOM change; a dropped socket in the real-Reverb variant is caught by a bounded web-first assertion timeout, never an unbounded hang. |
| Two-tab / stale approval | The approval spec opens the same request in a second context, approves in one, and asserts the other re-fetches to the current server state on next action rather than acting on a stale snapshot. |
| Company switch mid-journey | A spec that switches `X-Company-Id` asserts the query cache clears and no prior-tenant row remains visible — a cross-tenant leak in the UI would fail here and is corroborated by [`./SECURITY_TESTS.md`](./SECURITY_TESTS.md)'s API-level isolation suite. |
| Seed leakage between tests | Every `seed` fixture tears down its ephemeral company in the fixture's `use` epilogue; a leaked company would surface as a cross-test count assertion failure, caught early. |
| Reduced motion | A variant run with `prefers-reduced-motion: reduce` asserts confidence-meter fills and drawer transitions collapse to instant state changes, matching [`../frontend/README.md`](../frontend/README.md)'s motion rule. |
| No-JS visitor at `/login` | Asserted static-shell-only (per [`../frontend/flows/LOGIN_FLOW.md`](../frontend/flows/LOGIN_FLOW.md)); an accepted platform constraint, verified rather than treated as a bug. |
| Flaky third-party font load shifting layout | Fonts are self-hosted (`next/font`, `font-display: swap`) and pinned; the visual sweep waits on `document.fonts.ready` before screenshotting so a font race never produces a diff. |

# End of Document
