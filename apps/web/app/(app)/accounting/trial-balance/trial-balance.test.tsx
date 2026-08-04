import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { ComponentProps } from "react";
import type {
  ComputedTrialBalanceResult,
  FiscalPeriod,
  TrialBalanceRow,
  TrialBalanceSnapshot,
} from "@qayd/types";

import { LocaleProvider } from "../../../../lib/i18n/locale-provider";
import { TrialBalance } from "./trial-balance";

/**
 * The trial-balance screen (S2-12).
 *
 * What is worth asserting here is largely what the screen does NOT do. It never re-adds the column: the
 * totals rendered are the server's strings character for character, and "balanced" is the server's
 * boolean rather than a comparison performed in the browser — so there is a test that equal-looking
 * totals still read as out of balance when the server says they are. Beyond that: that a period change
 * genuinely re-reads, that a snapshot is presented as the durable artifact it is, and that the four
 * states an accountant can land in — empty calendar, no activity, loading, refused — each say
 * something true and distinct.
 *
 * The four-variant baseline is a render assertion, not a screenshot: the DoD asks that the screen work
 * in light/dark and LTR/RTL, and what that means testably is that every control and figure is present
 * and correctly labelled in all four.
 */

function period(overrides: Partial<FiscalPeriod> = {}): FiscalPeriod {
  return {
    id: 1,
    fiscal_year_id: 1,
    period_number: 1,
    name: "January 2026",
    start_date: "2026-01-01",
    end_date: "2026-01-31",
    status: "open",
    ...overrides,
  };
}

const periods: FiscalPeriod[] = [
  period(),
  period({
    id: 2,
    period_number: 2,
    name: "February 2026",
    start_date: "2026-02-01",
    end_date: "2026-02-28",
    status: "closed",
  }),
];

function row(overrides: Partial<TrialBalanceRow> = {}): TrialBalanceRow {
  return {
    account_id: 11,
    account_code: "1000",
    account_name_en: "Cash",
    account_name_ar: "النقد",
    normal_balance: "debit",
    opening_debit: "0.0000",
    opening_credit: "0.0000",
    period_debit: "250.0000",
    period_credit: "0.0000",
    closing_debit: "250.0000",
    closing_credit: "0.0000",
    is_abnormal_balance: false,
    source_line_count: 1,
    ...overrides,
  };
}

function balance(
  overrides: Partial<ComputedTrialBalanceResult> = {},
): ComputedTrialBalanceResult {
  return {
    fiscal_period_id: 1,
    period_start_date: "2026-01-01",
    as_of_date: "2026-01-31",
    total_debit: "250.0000",
    total_credit: "250.0000",
    variance: "0.0000",
    is_balanced: true,
    lines: [
      row(),
      row({
        account_id: 22,
        account_code: "4000",
        account_name_en: "Revenue",
        account_name_ar: "الإيرادات",
        normal_balance: "credit",
        period_debit: "0.0000",
        period_credit: "250.0000",
        closing_debit: "0.0000",
        closing_credit: "250.0000",
      }),
    ],
    ...overrides,
  };
}

function snapshot(
  overrides: Partial<TrialBalanceSnapshot> = {},
): TrialBalanceSnapshot {
  return {
    id: 9,
    fiscal_period_id: 1,
    period_start_date: "2026-01-01",
    as_of_date: "2026-01-31",
    type: "unadjusted",
    status: "draft",
    version: 1,
    is_current: true,
    parent_snapshot_id: null,
    currency_code: "KWD",
    total_debit: "250.0000",
    total_credit: "250.0000",
    variance: "0.0000",
    account_count: 2,
    line_count: 2,
    has_warnings: false,
    content_hash: null,
    approved_by: null,
    ...overrides,
  };
}

function renderScreen(
  props: Partial<ComponentProps<typeof TrialBalance>> = {},
  locale: "en" | "ar" = "en",
) {
  return render(
    <LocaleProvider initialLocale={locale}>
      <TrialBalance
        periods={periods}
        selectedPeriodId={1}
        balance={balance()}
        loadFailed={false}
        loadError={null}
        {...props}
      />
    </LocaleProvider>,
  );
}

/** Stub `fetch` with a queue of responses, capturing what was sent. */
function stubFetch(responses: { status: number; body: unknown }[]): {
  calls: { url: string; init: RequestInit }[];
} {
  const calls: { url: string; init: RequestInit }[] = [];
  let index = 0;

  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} });
      const next = responses[Math.min(index, responses.length - 1)];
      index += 1;
      return new Response(JSON.stringify(next?.body ?? null), {
        status: next?.status ?? 200,
      });
    }),
  );

  return { calls };
}

/** Open the period listbox and choose an option by its visible name. */
function choosePeriod(optionName: RegExp) {
  fireEvent.keyDown(screen.getByRole("combobox"), { key: "ArrowDown" });
  fireEvent.click(screen.getByRole("option", { name: optionName }));
}

beforeEach(() => {
  document.documentElement.classList.remove("dark");
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("the computed table", () => {
  it("renders the server's figures verbatim, without re-adding anything", () => {
    renderScreen();

    expect(screen.getByText("1000")).toBeDefined();
    expect(screen.getByText("Cash")).toBeDefined();
    expect(screen.getByText("4000")).toBeDefined();
    expect(screen.getByText("Revenue")).toBeDefined();

    // The amounts are the server's own strings, at the server's scale.
    expect(screen.getAllByText("250.0000").length).toBeGreaterThanOrEqual(4);
  });

  it("shows the date the figures are as of", () => {
    renderScreen();
    expect(screen.getByText("As of 2026-01-31")).toBeDefined();
  });

  it("reports the balance using the server's verdict, not its own arithmetic", () => {
    // The two totals are equal, yet the server says this does not balance. The screen must agree with
    // the server, because the tolerance the verdict was measured against lives there.
    renderScreen({
      balance: balance({
        total_debit: "250.0000",
        total_credit: "250.0000",
        variance: "0.0000",
        is_balanced: false,
      }),
    });

    expect(screen.getByText(/Out of balance by 0\.0000/)).toBeDefined();
    expect(screen.queryByText("Debits equal credits.")).toBeNull();
  });

  it("says whose verdict that is", () => {
    renderScreen();
    expect(screen.getByText(/the server's verdict/i)).toBeDefined();
  });

  it("flags an account carrying an abnormal balance", () => {
    renderScreen({
      balance: balance({ lines: [row({ is_abnormal_balance: true })] }),
    });

    expect(screen.getByText("Abnormal")).toBeDefined();
  });
});

describe("period selection", () => {
  it("re-reads the trial balance for the chosen period", async () => {
    const { calls } = stubFetch([
      {
        status: 200,
        body: { success: true, data: balance({ fiscal_period_id: 2 }) },
      },
    ]);

    renderScreen();
    choosePeriod(/February 2026/);

    await waitFor(() => expect(calls).toHaveLength(1));
    expect(calls[0]?.url).toContain(
      "/api/accounting/trial-balance?fiscal_period_id=2",
    );
  });

  it("shows the loading state while the period is being computed", async () => {
    stubFetch([{ status: 200, body: { success: true, data: balance() } }]);

    renderScreen();
    choosePeriod(/February 2026/);

    expect(screen.getByText("Computing the trial balance…")).toBeDefined();
    await waitFor(() =>
      expect(screen.queryByText("Computing the trial balance…")).toBeNull(),
    );
  });

  it("renders a refusal in the server's own words", async () => {
    stubFetch([
      {
        status: 403,
        body: {
          success: false,
          code: "PERMISSION_DENIED",
          message: "You do not have permission to read the trial balance.",
        },
      },
    ]);

    renderScreen();
    choosePeriod(/February 2026/);

    await waitFor(() =>
      expect(
        screen.getByText(
          "You do not have permission to read the trial balance.",
        ),
      ).toBeDefined(),
    );
  });
});

describe("snapshots", () => {
  it("generates a snapshot for the selected period and shows the artifact", async () => {
    const { calls } = stubFetch([
      {
        status: 201,
        body: { success: true, data: { snapshot: snapshot(), queued: false } },
      },
    ]);

    renderScreen();
    fireEvent.click(screen.getByRole("button", { name: "Generate snapshot" }));

    await waitFor(() => expect(screen.getByText("Snapshot #9")).toBeDefined());

    expect(calls[0]?.url).toBe("/api/accounting/trial-balance");
    expect(calls[0]?.init.method).toBe("POST");
    expect(JSON.parse(String(calls[0]?.init.body))).toMatchObject({
      fiscal_period_id: 1,
    });

    expect(screen.getByText("Version 1")).toBeDefined();
    expect(screen.getByText("Draft")).toBeDefined();
    expect(screen.getByText("Snapshot created.")).toBeDefined();
  });

  it("offers a refresh while a queued snapshot is still generating", async () => {
    stubFetch([
      {
        status: 202,
        body: {
          success: true,
          data: { snapshot: snapshot({ status: "generating" }), queued: true },
        },
      },
      { status: 200, body: { success: true, data: { snapshot: snapshot() } } },
    ]);

    renderScreen();
    fireEvent.click(screen.getByRole("button", { name: "Generate snapshot" }));

    await waitFor(() =>
      expect(screen.getByText(/handed to the reports queue/)).toBeDefined(),
    );

    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));

    await waitFor(() =>
      expect(screen.queryByText(/handed to the reports queue/)).toBeNull(),
    );
    expect(screen.getByText("Draft")).toBeDefined();
  });

  it("keeps the live view and the frozen artifact visibly distinct", async () => {
    stubFetch([
      {
        status: 201,
        body: { success: true, data: { snapshot: snapshot(), queued: false } },
      },
    ]);

    renderScreen();
    fireEvent.click(screen.getByRole("button", { name: "Generate snapshot" }));

    await waitFor(() =>
      expect(screen.getByText(/freezes these figures/)).toBeDefined(),
    );
  });
});

describe("states with nothing to show", () => {
  it("explains an empty calendar instead of guessing a period", () => {
    renderScreen({ periods: [], selectedPeriodId: null, balance: null });

    expect(screen.getByText("No fiscal periods yet")).toBeDefined();
    // Nothing to select, and nothing to freeze.
    expect(screen.queryByRole("combobox")).toBeNull();
    expect(
      screen.queryByRole("button", { name: "Generate snapshot" }),
    ).toBeNull();
  });

  it("distinguishes a period with no postings from a missing calendar", () => {
    renderScreen({ balance: balance({ lines: [] }) });

    expect(screen.getByText("Nothing posted in this period")).toBeDefined();
    expect(screen.queryByText("No fiscal periods yet")).toBeNull();
    // The period picker stays: another period may well have activity.
    expect(screen.getByRole("combobox")).toBeDefined();
  });

  it("surfaces a failed server-side read in the server's words", () => {
    renderScreen({
      balance: null,
      loadFailed: true,
      loadError: "The trial balance service is unavailable.",
    });

    expect(
      screen.getByText("The trial balance service is unavailable."),
    ).toBeDefined();
  });

  it("falls back to its own wording when the failure carried no message", () => {
    renderScreen({ balance: null, loadFailed: true, loadError: null });

    expect(
      screen.getByText(/The trial balance could not be loaded/),
    ).toBeDefined();
  });
});

describe.each([
  { name: "light · LTR", locale: "en" as const, dark: false, dir: "ltr" },
  { name: "dark · LTR", locale: "en" as const, dark: true, dir: "ltr" },
  { name: "light · RTL", locale: "ar" as const, dark: false, dir: "rtl" },
  { name: "dark · RTL", locale: "ar" as const, dark: true, dir: "rtl" },
])("baseline — $name", ({ locale, dark, dir }) => {
  it("renders the complete, labelled screen", async () => {
    if (dark) document.documentElement.classList.add("dark");

    renderScreen({}, locale);

    await waitFor(() =>
      expect(document.documentElement.getAttribute("dir")).toBe(dir),
    );
    expect(document.documentElement.classList.contains("dark")).toBe(dark);

    const labels =
      locale === "ar"
        ? {
            heading: "ميزان المراجعة",
            period: "الفترة",
            generate: "إنشاء نسخة مجمّدة",
            account: "النقد",
            total: "الإجمالي",
            balanced: "المدين يساوي الدائن.",
          }
        : {
            heading: "Trial balance",
            period: "Period",
            generate: "Generate snapshot",
            account: "Cash",
            total: "Total",
            balanced: "Debits equal credits.",
          };

    expect(
      screen.getByRole("heading", { level: 1, name: labels.heading }),
    ).toBeDefined();
    expect(screen.getByText(labels.period)).toBeDefined();
    expect(screen.getByRole("button", { name: labels.generate })).toBeDefined();

    // The account name follows the locale — the API carries both, and one is not a silent stand-in
    // for the other.
    expect(screen.getByText(labels.account)).toBeDefined();

    // The figures and the verdict survive in every variant.
    expect(screen.getByText(labels.total)).toBeDefined();
    expect(screen.getByText(labels.balanced)).toBeDefined();
    expect(screen.getAllByText("250.0000").length).toBeGreaterThanOrEqual(4);
  });
});
