import { describe, expect, it } from "vitest";
import type { AccountTreeNode } from "@qayd/types";

import {
  collectParentIds,
  countAccounts,
  filterTree,
  flattenAll,
  flattenVisible,
} from "./tree";

/**
 * The chart-of-accounts tree helpers (S2-10). These are the only place the screen derives anything, so
 * they are the part worth pinning down: what is visible at a given expansion, how deep it sits, and what
 * a search keeps.
 */

function node(
  id: number,
  code: string,
  children: AccountTreeNode[] = [],
): AccountTreeNode {
  return {
    id,
    code,
    name_en: `Account ${code}`,
    name_ar: `حساب ${code}`,
    parent_id: null,
    normal_balance: "debit",
    status: "active",
    is_control_account: false,
    // Matches what the database maintains: an account with children is a header.
    allow_posting: children.length === 0,
    control_account_of: null,
    account_type: null,
    children,
  };
}

const chart: AccountTreeNode[] = [
  node(1, "1000", [node(2, "1100", [node(3, "1110")]), node(4, "1200")]),
  node(5, "2000"),
];

describe("countAccounts", () => {
  it("counts every level, not just the roots", () => {
    expect(countAccounts(chart)).toBe(5);
  });

  it("is zero for an empty chart", () => {
    expect(countAccounts([])).toBe(0);
  });
});

describe("collectParentIds", () => {
  it("returns only the nodes that actually have children", () => {
    expect(collectParentIds(chart)).toEqual([1, 2]);
  });
});

describe("flattenVisible", () => {
  it("hides children of a collapsed node", () => {
    const rows = flattenVisible(chart, new Set());
    expect(rows.map((row) => row.account.code)).toEqual(["1000", "2000"]);
    expect(rows[0]?.hasChildren).toBe(true);
    expect(rows[0]?.isExpanded).toBe(false);
  });

  it("reveals one level per expanded ancestor, carrying depth", () => {
    const rows = flattenVisible(chart, new Set([1]));
    expect(rows.map((row) => row.account.code)).toEqual([
      "1000",
      "1100",
      "1200",
      "2000",
    ]);
    expect(rows.map((row) => row.depth)).toEqual([0, 1, 1, 0]);
  });

  it("does not reveal a grandchild while its parent is collapsed", () => {
    const rows = flattenVisible(chart, new Set([2]));
    expect(rows.map((row) => row.account.code)).toEqual(["1000", "2000"]);
  });

  it("marks a leaf as having no children, so it renders no expander", () => {
    const rows = flattenVisible(chart, new Set([1, 2]));
    const leaf = rows.find((row) => row.account.code === "1110");
    expect(leaf?.hasChildren).toBe(false);
    expect(leaf?.depth).toBe(2);
  });
});

describe("flattenAll", () => {
  it("returns every account regardless of expansion", () => {
    expect(flattenAll(chart).map((row) => row.account.code)).toEqual([
      "1000",
      "1100",
      "1110",
      "1200",
      "2000",
    ]);
  });
});

describe("filterTree", () => {
  it("returns the chart untouched for an empty query", () => {
    expect(filterTree(chart, "   ")).toBe(chart);
  });

  it("keeps a matching leaf's ancestors so the branch stays legible", () => {
    const result = filterTree(chart, "1110");
    expect(result).toHaveLength(1);
    expect(result[0]?.code).toBe("1000");
    expect(result[0]?.children[0]?.code).toBe("1100");
    expect(result[0]?.children[0]?.children[0]?.code).toBe("1110");
  });

  it("drops branches with no match anywhere", () => {
    const result = filterTree(chart, "2000");
    expect(result.map((n) => n.code)).toEqual(["2000"]);
  });

  it("matches the Arabic name as well as the English one", () => {
    expect(filterTree(chart, "حساب 2000").map((n) => n.code)).toEqual(["2000"]);
  });

  it("is case-insensitive", () => {
    expect(filterTree(chart, "ACCOUNT 2000").map((n) => n.code)).toEqual([
      "2000",
    ]);
  });

  it("returns nothing when nothing matches", () => {
    expect(filterTree(chart, "9999")).toEqual([]);
  });
});
