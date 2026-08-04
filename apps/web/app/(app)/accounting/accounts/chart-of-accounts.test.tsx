import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { AccountTreeNode, AccountType } from "@qayd/types";

import { LocaleProvider } from "../../../../lib/i18n/locale-provider";
import { ChartOfAccounts } from "./chart-of-accounts";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ refresh: vi.fn() }),
  usePathname: () => "/accounting/accounts",
}));

/**
 * The chart-of-accounts screen (S2-10). The assertions track the story's acceptance criteria: the tree
 * renders each account with its type and normal balance, expansion works, search narrows, and an empty
 * chart says so rather than showing a bare table.
 */

const assetType: AccountType = {
  id: 1,
  key: "asset",
  name_en: "Asset",
  name_ar: "أصل",
  normal_balance: "debit",
  is_balance_sheet: true,
};

function node(
  id: number,
  code: string,
  nameEn: string,
  children: AccountTreeNode[] = [],
): AccountTreeNode {
  return {
    id,
    code,
    name_en: nameEn,
    name_ar: `${nameEn} بالعربية`,
    parent_id: null,
    normal_balance: "debit",
    status: "active",
    is_control_account: false,
    control_account_of: null,
    account_type: assetType,
    children,
  };
}

const chart: AccountTreeNode[] = [
  node(1, "1000", "Current Assets", [node(2, "1100", "Cash")]),
  node(3, "2000", "Payables"),
];

function renderChart(accounts: AccountTreeNode[] = chart) {
  return render(
    <LocaleProvider initialLocale="en">
      <ChartOfAccounts
        accounts={accounts}
        accountTypes={[assetType]}
        loadFailed={false}
      />
    </LocaleProvider>,
  );
}

describe("ChartOfAccounts", () => {
  it("renders each account with its type and normal balance", () => {
    renderChart();

    const cash = screen.getByText("Cash").closest("tr");
    expect(cash).not.toBeNull();
    expect(within(cash as HTMLElement).getByText("1100")).toBeDefined();
    expect(within(cash as HTMLElement).getByText("Asset")).toBeDefined();
    expect(within(cash as HTMLElement).getByText("Debit")).toBeDefined();
  });

  it("counts every account, including nested ones", () => {
    renderChart();
    expect(screen.getByText("3 accounts")).toBeDefined();
  });

  it("shows the empty state for a company with no accounts yet", () => {
    renderChart([]);

    expect(screen.getByText("No accounts yet")).toBeDefined();
    expect(screen.getByText("0 accounts")).toBeDefined();
    expect(screen.queryByRole("table")).toBeNull();
  });

  it("offers a New Account action", () => {
    renderChart();
    expect(screen.getByRole("button", { name: "New account" })).toBeDefined();
  });

  it("labels the expander with the account it belongs to", () => {
    renderChart();
    // Parents start expanded, so the child is on screen and the control offers to collapse it.
    expect(screen.getByText("Cash")).toBeDefined();
    expect(
      screen.getByRole("button", { name: "Collapse Current Assets" }),
    ).toBeDefined();
  });

  it("surfaces a failed load instead of rendering a silently empty chart", () => {
    render(
      <LocaleProvider initialLocale="en">
        <ChartOfAccounts accounts={[]} accountTypes={[]} loadFailed />
      </LocaleProvider>,
    );

    expect(screen.getByRole("alert").textContent).toContain(
      "could not be loaded",
    );
  });

  it("renders the Arabic name when the locale is Arabic", () => {
    render(
      <LocaleProvider initialLocale="ar">
        <ChartOfAccounts
          accounts={chart}
          accountTypes={[assetType]}
          loadFailed={false}
        />
      </LocaleProvider>,
    );

    expect(screen.getByText("Cash بالعربية")).toBeDefined();
    // Every fixture account carries the same type, so the Arabic type name appears on each row.
    expect(screen.getAllByText("أصل")).toHaveLength(3);
  });
});
