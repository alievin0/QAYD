# Unit Tests — QAYD Testing
Version: 1.0
Status: Design Specification
Module: Testing
Submodule: UNIT_TESTS
---

# Purpose

Unit tests are the widest band of QAYD's test pyramid (see [TESTING_STRATEGY.md](./TESTING_STRATEGY.md))
and the layer where the platform's correctness is cheapest to prove and cheapest to keep proven. A unit
test exercises one piece of logic — a service method, a hook, a Zod schema, a tool function — in
isolation from its slow collaborators (the database, the network, the model, the browser), so it runs
in milliseconds and fails for exactly one reason. This document specifies how QAYD writes unit tests
across its three codebases: the Laravel 12 / PHP 8.4 backend with Pest/PHPUnit, the Next.js 15 / React
19 frontend with Vitest + Testing Library, and the FastAPI / Python AI engine with pytest.

The through-line is that the unit layer owns the *invariants that must hold regardless of how they are
reached*. The single most important is the double-entry posting rule — `SUM(debits) = SUM(credits)`
per entry, and posted records are immutable — which QAYD enforces structurally in a pure domain service
(see [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) `# Transactions and the
Double-Entry Posting Invariant`) and therefore proves structurally in a pure unit test with no
database at all. The second is that the AI never buys autonomy with confidence: a sensitive operation
is forced to `requires_approval` regardless of how sure the model is, computed by a side-effect-free
`AutonomyResolver` that is, deliberately, the most-covered class in its module. Both are unit-testable
precisely because QAYD's architecture pushed them into pure, injectable objects.

This document covers, per codebase: what belongs in a unit test versus an integration test, the mocking
boundaries, naming and structure conventions, worked examples of every category, coverage expectations,
and the edge cases a financial system's unit suite must not miss. Cross-codebase, API-level, and
database tests are [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md)'s subject; this document stops at the
boundary where a real database or a real HTTP kernel would be needed.

# Scope

In scope:

- **Backend:** services and actions with their collaborators mocked; the `PostingService` balance
  invariant and posted-immutability guard; value objects (`Money`, `ExchangeRate`, `TaxRate`); the
  state-machine guards; policy classes; the `AutonomyResolver`.
- **Frontend:** presentational and logic components rendered in jsdom; custom hooks in isolation; Zod
  form schemas mirroring the backend `FormRequest`; derived totals and selectors; `Intl`-based
  number/date/currency formatters and their RTL/locale behavior.
- **AI engine:** deterministic tool functions (the pure input→output helpers an agent calls); confidence
  normalization and per-field gating; prompt-context assembly scoping; retry/backoff logic — everything
  that does not require a live model call.
- Mocking boundaries, the unit-vs-integration line, coverage floors, and edge cases per codebase.

Out of scope (owned by [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md)):

- Anything that needs a real PostgreSQL, the real HTTP kernel, the real middleware stack, MSW-mocked
  network round-trips, or the real engine ↔ backend contract wire.
- Playwright E2E and four-variant visual regression (owned by the frontend E2E layer).
- Model *answer quality* (owned by the FastAPI eval harness; non-gating here).

# Tooling

| Codebase | Runner | Assertion / render | Mocking | Coverage |
|---|---|---|---|---|
| Backend | Pest (PHPUnit under the hood) | Pest `expect()` + PHPUnit assertions | Mockery, `$this->mock()`, hand-rolled fakes | pcov / Xdebug, `--coverage` |
| Frontend | Vitest | `@testing-library/react`, `@testing-library/user-event`, `expect` | `vi.fn`, `vi.mock`, `vi.spyOn`, fake timers | v8 coverage (`--coverage`) |
| AI engine | pytest | `assert`, `pytest.approx`, `pytest.raises` | `unittest.mock`, `monkeypatch`, `pytest` fixtures | `coverage.py` / `pytest-cov` |

Unit tests never open a socket, never touch a real database, and never call a real model provider. A
test that needs any of those is, by definition, not a unit test and belongs in the integration layer.

# Conventions & Structure

## The unit-vs-integration line

The line is drawn by *collaborators*, not by size. A test is a **unit** test when every collaborator
that would do I/O is replaced by a mock, fake, or in-memory double; it is an **integration** test the
moment a real database, real HTTP kernel, real network, or real browser participates.

| Subject | Unit (this doc) | Integration ([INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md)) |
|---|---|---|
| `CreateInvoiceAction` | Repository + tax service mocked; assert the returned object, emitted events, transaction boundary | Through `POST /api/v1/sales/invoices` with a real DB and envelope |
| `PostingService::assertBalanced` | Pure — call it with a draft, assert it throws or returns | Through posting an entry and asserting `ledger_entries` materialized |
| A Zod schema | `schema.safeParse(input)` in isolation | The form component submitting to an MSW-mocked API |
| A query hook | (n/a — needs the query client + network) | `useJournalEntries` against MSW |
| A confidence normalizer | Pure function, table of inputs → outputs | The proposal round-trip against a fake Laravel |

When in doubt: if replacing the collaborator with a mock changes *what the test is really checking*,
it belongs in integration; if the mock is just there to keep the unit fast and deterministic, it is a
unit test.

## Naming & structure

- **Backend:** files mirror the class under test (`PostingServiceTest.php`, `AutonomyResolverTest.php`)
  under `tests/Unit/<Module>/`. Cases are `it('present-tense behavior', ...)`; datasets (`->with([...])`)
  drive truth-table coverage. One behavior per `it`.
- **Frontend:** specs are colocated by feature under `tests/unit/` as `<subject>.spec.ts(x)`; a
  `describe(subject)` block groups `it('renders/derives/formats X')` cases. Components are queried by
  role/label (accessible queries), never by CSS class or test-id-only where a role exists.
- **AI engine:** `test_<subject>.py` under `ai/tests/unit/`; functions `test_<behavior>`; parametrized
  via `@pytest.mark.parametrize` for truth tables. Fixtures live in `conftest.py`.

Every unit test is arranged Arrange–Act–Assert and reads top-to-bottom without a reader needing to know
the framework's magic. Shared builders (a `decision_draft(...)` helper, a `balancedDraft()` factory) keep
the Arrange block one line so the Assert block is the focus.

# Patterns

## Mock the boundary, not the logic

Mock the seam where I/O would happen — the repository, the HTTP client, the engine client, `fetch` —
and never mock the thing under test. A `PostingService` test does not mock the balance check (that *is*
the logic); it mocks the `LedgerRepository` so no database is touched. An `AutonomyResolver` test mocks
nothing at all, because the class is already pure. Over-mocking is a smell: if a unit test mocks the
method it is supposedly testing, it asserts the mock, not the code.

## Pure domain objects are tested without any double

QAYD's architecture deliberately isolates invariants into pure classes (`PostingService`,
`AutonomyResolver`, `Money`, the state machines) so they can be proven in isolation. These get the
densest tests in the suite — full truth tables, boundary values, and every documented exception — and
they need no mocking framework because they have no collaborators that do I/O.

## Deterministic inputs, deterministic outputs

Pin time, seed randomness, and pass every dependency in explicitly. A formatter test sets the locale;
a resolver test passes the settings object; a schema test passes the exact payload. Nothing is read
from ambient global state, so the test's result depends only on its inputs.

# What to Test / Coverage

## Backend (Pest / PHPUnit)

### Services and actions

Actions are unit-tested with their repositories and the AI-engine client mocked. The assertion targets
are the returned domain object, the events emitted, and that a write ran inside a transaction — never a
database row (that is the feature layer's job).

```php
// tests/Unit/Sales/CreateInvoiceActionTest.php

use App\Actions\Sales\CreateInvoiceAction;
use App\Data\Sales\{InvoiceData, InvoiceLineData};
use App\Events\Sales\InvoiceCreated;
use App\Models\{Sales\Invoice, User};
use App\Repositories\Sales\InvoiceRepository;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Support\Facades\Event;

it('creates an invoice, applies line tax, and emits InvoiceCreated', function () {
    Event::fake([InvoiceCreated::class]);

    $invoice = Invoice::factory()->makeOne(); // in-memory, never persisted in a unit test
    $repo = Mockery::mock(InvoiceRepository::class);
    $repo->shouldReceive('create')->once()->andReturn($invoice);

    $tax = Mockery::mock(TaxCalculationService::class);
    $tax->shouldReceive('forLine')->andReturn(fakeLineTax('7.5000'));

    $action = new CreateInvoiceAction($repo, $tax);

    $result = $action->execute(invoiceDataWithOneLine(), User::factory()->makeOne(['id' => 42]));

    expect($result)->toBeInstanceOf(Invoice::class);
    Event::assertDispatched(InvoiceCreated::class, fn ($e) => $e->actorId === 42);
});
```

Note the two things a unit test proves here that no other layer proves as cheaply: the tax service is
*called per line* (a mock expectation, not a DB read), and the event is emitted with the actor id.

### The posting engine — the double-entry invariant

This is the highest-value unit test in the backend. `PostingService` is pure with respect to HTTP and
owns the `debit = credit` invariant; its unit tests assert both the happy path and every way an entry
can be unbalanced, using fixed-scale `NUMERIC(19,4)` money value objects so rounding can never silently
unbalance an entry.

```php
// tests/Unit/Accounting/PostingServiceTest.php

use App\Domain\Accounting\JournalDraft;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Services\Accounting\PostingService;
use App\Repositories\Accounting\LedgerRepository;

beforeEach(function () {
    // The ledger repo is mocked: a unit test never writes ledger rows.
    $this->ledger = Mockery::mock(LedgerRepository::class);
    $this->service = new PostingService($this->ledger);
});

it('accepts a balanced draft and appends one ledger line per draft line', function () {
    $this->ledger->shouldReceive('createEntry')->once()->andReturn(fakeEntry());
    $this->ledger->shouldReceive('appendLine')->twice();

    $draft = JournalDraft::make()
        ->debit(accountCode: '1000', amount: '120.0000')
        ->credit(accountCode: '4000', amount: '120.0000');

    expect(fn () => $this->service->post($draft))->not->toThrow(UnbalancedEntryException::class);
});

it('rejects an unbalanced draft before any ledger write', function () {
    $this->ledger->shouldNotReceive('createEntry'); // proves it fails BEFORE persistence
    $this->ledger->shouldNotReceive('appendLine');

    $draft = JournalDraft::make()
        ->debit('1000', '120.0000')
        ->credit('4000', '100.0000');

    expect(fn () => $this->service->post($draft))
        ->toThrow(UnbalancedEntryException::class);
});

it('does not let floating-point rounding unbalance a 3-line entry', function () {
    // 0.1 + 0.2 style traps: fixed-scale money must sum exactly.
    $draft = JournalDraft::make()
        ->debit('1000', '0.1000')
        ->debit('1010', '0.2000')
        ->credit('4000', '0.3000');

    expect(fn () => $this->service->post($draft))->not->toThrow(UnbalancedEntryException::class);
})->with([
    ['0.1000', '0.2000', '0.3000'],
    ['33.3300', '33.3300', '66.6600'],
    ['999999999999999.9999', '0.0001', '1000000000000000.0000'],
]);
```

### Posted immutability as a domain guard

The state machine that refuses to mutate a posted record is unit-testable without a database because it
operates on the aggregate's status, not on persistence.

```php
// tests/Unit/Accounting/JournalEntryStateTest.php

use App\Domain\Accounting\JournalEntryState;
use App\Exceptions\Accounting\{ImmutableRecordException, InvalidStateTransitionException};

it('forbids editing a posted entry', function () {
    $state = JournalEntryState::posted();
    expect(fn () => $state->assertMutable())->toThrow(ImmutableRecordException::class);
});

it('allows draft → posted but forbids posted → draft', function () {
    expect(JournalEntryState::draft()->canTransitionTo('posted'))->toBeTrue();
    expect(fn () => JournalEntryState::posted()->transitionTo('draft'))
        ->toThrow(InvalidStateTransitionException::class);
});
```

### Value objects

Money and rate value objects are the arithmetic substrate of every total in the platform; they get
exhaustive boundary tests.

```php
// tests/Unit/Support/MoneyTest.php

use App\Support\Money;

it('adds and compares money at fixed 4-dp scale without float drift', function () {
    expect(Money::of('0.1000')->plus(Money::of('0.2000'))->equals(Money::of('0.3000')))->toBeTrue();
});

it('refuses to add across currencies', function () {
    expect(fn () => Money::of('10.0000', 'KWD')->plus(Money::of('10.0000', 'USD')))
        ->toThrow(App\Exceptions\CurrencyMismatchException::class);
});

it('rounds half-up to 4 decimal places deterministically', function (string $in, string $out) {
    expect(Money::of($in)->toString())->toBe($out);
})->with([
    ['1.00005', '1.0001'],
    ['1.00004', '1.0000'],
    ['-0.00005', '-0.0001'],
]);
```

### Policies

Policy classes answer "may this actor do this in the active company?" and are pure over a `User` and an
optional model, so they unit-test without touching Gate wiring.

```php
// tests/Unit/Sales/InvoicePolicyTest.php

use App\Models\{Sales\Invoice, User};
use App\Policies\Sales\InvoicePolicy;

it('lets a permitted user void an invoice they did not create', function () {
    $actor = userWithPermission('sales.invoice.void', id: 7);
    $invoice = Invoice::factory()->makeOne(['created_by' => 9]);

    expect((new InvoicePolicy)->void($actor, $invoice))->toBeTrue();
});

it('enforces maker-checker: the creator cannot void their own invoice', function () {
    $actor = userWithPermission('sales.invoice.void', id: 7);
    $invoice = Invoice::factory()->makeOne(['created_by' => 7]);

    expect((new InvoicePolicy)->void($actor, $invoice))->toBeFalse();
});
```

### The AutonomyResolver — AI human-in-the-loop, proven exhaustively

`AutonomyResolver` is a pure function computing `auto | suggest_only | requires_approval`. It is the
mechanism that guarantees no agent gets a laxer autonomy path than any other, so it is tested across
the full truth table, per-agent, and a regression here is treated as a governance failure.

```php
// tests/Unit/Ai/AutonomyResolverTest.php

use App\Services\Ai\{AutonomyResolver, AiDecisionDraft, AgentSettings};

beforeEach(fn () => config()->set('ai.sensitive_operations', [
    'bank.transfer', 'payroll.release', 'tax.submit', 'ai.settings.update',
]));

it('forces every sensitive operation to requires_approval regardless of confidence', function (string $op) {
    $draft = new AiDecisionDraft(decisionType: $op, confidence: 0.99,
        reversibility: 'reversible', expectedImpactAmount: 1.0);

    expect((new AutonomyResolver)->resolve($draft, settings(autoPostConfidence: 0.5, autoPostMaxAmount: 1e9)))
        ->toBe('requires_approval');
})->with(['bank.transfer', 'payroll.release', 'tax.submit', 'ai.settings.update']);

it('forces an irreversible non-sensitive op to requires_approval', function () {
    $draft = new AiDecisionDraft('inventory.write_off', 0.99, 'irreversible', 5.0);
    expect((new AutonomyResolver)->resolve($draft, settings(0.7, 1000)))->toBe('requires_approval');
});

it('auto-executes only inside both the confidence and the amount ceiling', function (
    float $confidence, float $amount, string $expected
) {
    $draft = new AiDecisionDraft('journal_entry.draft', $confidence, 'reversible', $amount);
    expect((new AutonomyResolver)->resolve($draft, settings(0.90, 500)))->toBe($expected);
})->with([
    'clears both'        => [0.95, 100.0, 'auto'],
    'below confidence'   => [0.80, 100.0, 'suggest_only'],
    'above amount'       => [0.95, 900.0, 'suggest_only'],
    'exactly at both'    => [0.90, 500.0, 'auto'],
]);
```

## Frontend (Vitest + Testing Library)

### Components

Components are rendered in jsdom and queried by accessible role/label; behavior is driven with
`user-event`, never by poking internal state. A component test asserts what a user perceives — text,
disabled state, presence of an affordance — and the RBAC/i18n behavior the frontend owns as an
experience-quality concern.

```tsx
// tests/unit/components/confidence-badge.spec.tsx
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { ConfidenceBadge } from "@/components/shared/confidence-badge";

describe("ConfidenceBadge", () => {
  it("renders the confidence as a percentage with the AI-visible reasoning affordance", () => {
    render(<ConfidenceBadge score={0.94} reasoning="Matched 47 prior bills" />);
    expect(screen.getByText("94%")).toBeInTheDocument();
    // AI is visible, never silent — the reasoning must be reachable.
    expect(screen.getByRole("button", { name: /why/i })).toBeInTheDocument();
  });

  it("flags low confidence rather than presenting it as certain", () => {
    render(<ConfidenceBadge score={0.42} reasoning="Cropped OCR field" />);
    expect(screen.getByRole("status")).toHaveAttribute("data-confidence", "low");
  });
});
```

```tsx
// tests/unit/components/permission-gate.spec.tsx
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { PermissionGate } from "@/components/shared/permission-gate";
import { PermissionProvider } from "@/lib/auth/permission-context";

const renderWith = (perms: string[], ui: React.ReactNode) =>
  render(<PermissionProvider value={new Set(perms)}>{ui}</PermissionProvider>);

describe("PermissionGate", () => {
  it("renders children when the permission is held", () => {
    renderWith(["accounting.journal.post"], (
      <PermissionGate permission="accounting.journal.post"><button>Post</button></PermissionGate>
    ));
    expect(screen.getByRole("button", { name: "Post" })).toBeInTheDocument();
  });

  it("renders nothing when the permission is absent (courtesy hide, not security)", () => {
    renderWith([], (
      <PermissionGate permission="accounting.journal.post"><button>Post</button></PermissionGate>
    ));
    expect(screen.queryByRole("button", { name: "Post" })).not.toBeInTheDocument();
  });
});
```

### Hooks

Non-network hooks (a reducer-style UI hook, a derived-selector hook) are tested with
`renderHook` + `act`. Data-fetching hooks are *not* unit tested — they need the query client and a
mocked network and belong in [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md).

```tsx
// tests/unit/hooks/use-journal-line-grid.spec.ts
import { renderHook, act } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { useJournalLineGrid } from "@/hooks/use-journal-line-grid";

describe("useJournalLineGrid", () => {
  it("recomputes the running balance as lines change and blocks posting while unbalanced", () => {
    const { result } = renderHook(() => useJournalLineGrid());

    act(() => result.current.addLine({ accountId: 1, debit: "120.0000", credit: "0.0000" }));
    expect(result.current.canPost).toBe(false); // one-sided → unbalanced

    act(() => result.current.addLine({ accountId: 2, debit: "0.0000", credit: "120.0000" }));
    expect(result.current.difference).toBe("0.0000");
    expect(result.current.canPost).toBe(true);
  });
});
```

### Zod schemas

Each form has one Zod schema mirroring the Laravel `FormRequest` field-for-field; the schema is unit
tested so the frontend fails fast on the exact shapes the backend would reject. A frontend rule the
backend does not also enforce is a bug — the schema test is where that discipline is asserted.

```ts
// tests/unit/validation/journal-entry-schema.spec.ts
import { describe, it, expect } from "vitest";
import { JournalEntrySchema } from "@/lib/validation/journal-entry";

describe("JournalEntrySchema", () => {
  it("accepts a balanced two-line entry", () => {
    const result = JournalEntrySchema.safeParse({
      entryDate: "2026-07-16",
      currencyCode: "KWD",
      lines: [
        { accountId: 1, debit: "120.0000", credit: "0.0000" },
        { accountId: 2, debit: "0.0000", credit: "120.0000" },
      ],
    });
    expect(result.success).toBe(true);
  });

  it("rejects fewer than two lines, mirroring the backend min:2 rule", () => {
    const result = JournalEntrySchema.safeParse({
      entryDate: "2026-07-16", currencyCode: "KWD",
      lines: [{ accountId: 1, debit: "120.0000", credit: "0.0000" }],
    });
    expect(result.success).toBe(false);
    if (!result.success) expect(result.error.issues[0].path).toContain("lines");
  });

  it("rejects an unsupported currency", () => {
    const result = JournalEntrySchema.safeParse({
      entryDate: "2026-07-16", currencyCode: "GBP",
      lines: [{ accountId: 1, debit: "1.0000", credit: "0.0000" },
              { accountId: 2, debit: "0.0000", credit: "1.0000" }],
    });
    expect(result.success).toBe(false);
  });
});
```

### Derived totals

Any figure the UI derives client-side (a running balance, a page subtotal) is unit tested against the
same fixed-scale expectation the backend uses, so the two never disagree on screen.

```ts
// tests/unit/accounting/derive-balance.spec.ts
import { describe, it, expect } from "vitest";
import { deriveBalance } from "@/lib/accounting/derive-balance";

describe("deriveBalance", () => {
  it.each([
    [[["120.0000", "0.0000"], ["0.0000", "120.0000"]], true, "0.0000"],
    [[["120.0000", "0.0000"], ["0.0000", "100.0000"]], false, "20.0000"],
    [[["0.1000", "0.0000"], ["0.2000", "0.0000"], ["0.0000", "0.3000"]], true, "0.0000"],
  ])("balances %j → balanced=%s diff=%s", (lines, balanced, diff) => {
    const rows = lines.map(([debit, credit]) => ({ debit, credit }));
    const r = deriveBalance(rows);
    expect(r.balanced).toBe(balanced);
    expect(r.difference).toBe(diff);
  });
});
```

### Formatters (i18n, RTL, currency)

Formatters wrap `Intl` and are tested per locale. Financial figures render Western Arabic numerals even
under the Arabic locale (a deliberate override, because mixed numeral systems in one ledger row read as
an error), and that behavior is asserted rather than assumed.

```ts
// tests/unit/i18n/format.spec.ts
import { describe, it, expect } from "vitest";
import { formatMoney, formatDate } from "@/lib/i18n/format";

describe("formatMoney", () => {
  it("formats KWD to 3 fraction digits in English", () => {
    expect(formatMoney("1234.5", { locale: "en", currency: "KWD" })).toBe("KWD 1,234.500");
  });

  it("keeps Western Arabic numerals under the ar locale for ledger legibility", () => {
    // latn numbering override — figures must not switch to Eastern Arabic numerals in a ledger.
    expect(formatMoney("1234.5", { locale: "ar", currency: "KWD" })).toMatch(/1,234\.500/);
  });
});

describe("formatDate", () => {
  it("localizes month names by locale without changing the calendar value", () => {
    const iso = "2026-07-16";
    expect(formatDate(iso, "en")).toMatch(/Jul/);
    expect(formatDate(iso, "ar")).not.toMatch(/Jul/);
  });
});
```

## AI engine (pytest)

### Deterministic tools

The engine's tools are pure input→output helpers an agent calls; they are unit tested with no model in
the loop. Their determinism is the property that makes the agent auditable.

```python
# ai/tests/unit/test_tools_match_vendor.py
import pytest
from qayd_ai.tools.matching import match_vendor

def test_exact_name_match_returns_full_confidence():
    result = match_vendor("Gulf Prime Distribution Co.", candidates=[
        {"id": 5521, "name": "Gulf Prime Distribution Co."},
    ])
    assert result.vendor_id == 5521
    assert result.confidence == pytest.approx(1.0)

def test_no_candidate_returns_none_not_a_guess():
    # A tool must abstain rather than fabricate a match — the human-in-the-loop depends on it.
    result = match_vendor("Unknown LLC", candidates=[])
    assert result.vendor_id is None
    assert result.confidence == pytest.approx(0.0)

@pytest.mark.parametrize("query,expected", [
    ("gulf prime distribution", 5521),   # case/spacing tolerant
    ("Gulf  Prime  Distribution  Co", 5521),
])
def test_fuzzy_match_is_deterministic(query, expected):
    candidates = [{"id": 5521, "name": "Gulf Prime Distribution Co."}]
    assert match_vendor(query, candidates).vendor_id == expected
```

### Confidence normalization and per-field gating

The engine normalizes raw model confidence into the `0.0000`–`1.0000` fixed-scale the backend contract
expects, and gates per-field so a cropped OCR field is flagged rather than guessed. Both are pure and
exhaustively tested.

```python
# ai/tests/unit/test_confidence.py
import pytest
from qayd_ai.confidence import normalize, gate_fields

@pytest.mark.parametrize("raw,expected", [
    (0.9376, "0.9376"),
    (1.2, "1.0000"),      # clamp above
    (-0.1, "0.0000"),     # clamp below
    (0.5, "0.5000"),      # fixed 4-dp string, matches NUMERIC(19,4) on the PHP side
])
def test_normalize_clamps_and_fixes_scale(raw, expected):
    assert normalize(raw) == expected

def test_low_confidence_field_is_flagged_not_filled():
    fields = {"total": 0.98, "tax_id": 0.34}
    gated = gate_fields(fields, threshold=0.70)
    assert gated["total"].accepted is True
    assert gated["tax_id"].accepted is False       # surfaced to the human, never auto-filled
    assert gated["tax_id"].reason == "below_confidence_threshold"
```

### Prompt-context scoping

The agent must never assemble context beyond the initiating principal's permission grant. The
context-assembly helper is unit tested against a stubbed permission set so a payroll-blind user's
context provably excludes payroll — the model restraint is enforced in code, not hoped for.

```python
# ai/tests/unit/test_context_scoping.py
from qayd_ai.context import assemble_context

def test_context_excludes_data_the_user_cannot_read():
    ctx = assemble_context(
        principal_permissions={"accounting.read"},   # no payroll.read
        available_sources=["ledger", "payroll", "invoices"],
    )
    assert "payroll" not in ctx.included_sources
    assert set(ctx.included_sources) <= {"ledger", "invoices"}
```

## Coverage floors

| Codebase | Unit-band target | Non-negotiable |
|---|---|---|
| Backend money/posting/AI-governance classes | 95% line / 90% branch | `PostingService`, `AutonomyResolver`, value objects, state machines at 100% branch of their guard logic |
| Backend overall unit | 85% line | Every policy class has a permitted-and-denied test |
| Frontend | 80% line / 75% branch | Every Zod schema and every derived-total helper has a unit test |
| AI engine deterministic tools | 90% line / 85% branch | Every tool tested for its abstain path (returns none, not a guess) |

# Examples

## A backend unit test that would have caught a real bug

```php
// A regression that silently changed >= to > on the amount ceiling would auto-execute
// a proposal that should have been suggest_only. This boundary test fails on that change.
it('treats a proposal exactly at the amount ceiling as auto, not suggest_only', function () {
    $draft = new AiDecisionDraft('journal_entry.draft', 0.95, 'reversible', 500.0);
    expect((new AutonomyResolver)->resolve($draft, settings(0.90, 500)))->toBe('auto');
});
```

## A frontend unit test guarding an RTL regression at the logic layer

```ts
// The formatter, not the CSS, decides numeral system. A change that let the ar locale
// switch a ledger figure to Eastern Arabic numerals is caught here, before the screen renders.
it("never emits Eastern Arabic numerals for a money figure", () => {
  const out = formatMoney("2026.0", { locale: "ar", currency: "KWD" });
  expect(out).not.toMatch(/[٠-٩]/); // Arabic-Indic digit range
});
```

## An AI-engine unit test guarding the abstain contract

```python
def test_ocr_extractor_abstains_on_unreadable_field(fake_ocr_page):
    fake_ocr_page.set_field_quality("iban", quality=0.2)
    result = extract_bill_fields(fake_ocr_page, threshold=0.7)
    assert result.fields["iban"].value is None
    assert result.fields["iban"].needs_human is True
```

# Edge Cases

- **Rounding at the boundary of scale.** Money tests must include values that trip floating-point
  intuition (`0.1 + 0.2`, half-up at the 4th decimal, the largest representable `NUMERIC(19,4)`), because
  a silent rounding error is exactly the kind of bug that unbalances an entry without throwing anywhere.
- **A mock that hides the very failure being tested.** If a `PostingService` test mocks the balance
  check, it asserts the mock and proves nothing; the balance logic must run for real, only the ledger
  repository is doubled. Reviews reject a unit test whose mock swallows the subject.
- **The confidence-ceiling off-by-one.** `>=` versus `>` on the confidence and amount ceilings changes
  whether a proposal auto-executes; both boundaries (exactly-at, just-below, just-above) are asserted so
  a one-character regression fails a test.
- **A Zod rule the backend does not have.** A frontend schema that rejects input the backend would
  accept degrades UX silently and diverges from the contract; schema tests assert parity with the
  `FormRequest` rules, and a frontend-only rule must be justified as a fail-fast convenience, not a new
  validation of record.
- **Numeral-system leakage in Arabic.** A formatter change that lets a ledger figure render in Eastern
  Arabic numerals is a legibility regression an accountant reads as an error; formatter tests assert the
  Western-Arabic override under the `ar` locale explicitly.
- **A tool that guesses instead of abstaining.** An AI tool that returns a fabricated match at low
  confidence defeats the human-in-the-loop; every tool has an explicit abstain test proving it returns
  none (or `needs_human`), never a confident fabrication.
- **Time-dependent unit logic.** A resolver or formatter that reads "now" must have the clock pinned;
  an unpinned clock in a unit test is treated as a flaky test on sight (see
  [TESTING_STRATEGY.md](./TESTING_STRATEGY.md) `# Flaky-test policy`).

# Related Documents

- [TESTING_STRATEGY.md](./TESTING_STRATEGY.md) — the overall pyramid, coverage philosophy, CI gates,
  and the flaky-test policy this document's suites run under.
- [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) — where the unit-vs-integration line lands on the
  integration side: API feature tests, tenant isolation, MSW-mocked frontend, and the AI contract.
- [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) — the Action/Service/DTO/value-object
  layering these unit tests target and why it is built for testability.
- [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) — the `AutonomyResolver` and the human-in-the-loop
  boundary the AI-governance unit tests prove.
- [../frontend/README.md](../frontend/README.md) — the Vitest/Testing-Library conventions, the i18n
  parity rule, and the design-token/formatter expectations the frontend unit tests assert.
- [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)

# End of Document
