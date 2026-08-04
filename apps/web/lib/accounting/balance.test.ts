import { describe, expect, it } from "vitest";

import {
  deriveBalance,
  fromMinor,
  isCompleteLine,
  toMinor,
  type DraftLine,
} from "./balance";

/**
 * The advisory balance derivation (S2-11).
 *
 * The cases that matter are the ones a float would get wrong, because the whole reason this module uses
 * scaled `bigint` is that an editor disagreeing with the ledger by a thousandth of a fils is worse than
 * one doing no arithmetic at all.
 */

function line(partial: Partial<DraftLine> = {}): DraftLine {
  return {
    accountId: 1,
    debit: "0",
    credit: "0",
    description: "",
    ...partial,
  };
}

describe("toMinor / fromMinor", () => {
  it("scales a money literal to minor units", () => {
    expect(toMinor("100.0000")).toBe(1000000n);
    expect(toMinor("0.0001")).toBe(1n);
    expect(toMinor("7")).toBe(70000n);
  });

  it("pads and truncates to the ledger's four decimal places", () => {
    expect(toMinor("1.5")).toBe(15000n);
    expect(toMinor("1.23456")).toBe(12345n);
  });

  it("treats a half-typed or unparseable value as zero rather than throwing", () => {
    expect(toMinor("")).toBe(0n);
    expect(toMinor("   ")).toBe(0n);
    expect(toMinor(".")).toBe(0n);
    expect(toMinor("abc")).toBe(0n);
    expect(toMinor("-5")).toBe(0n);
  });

  it("round-trips through the fixed-scale format", () => {
    expect(fromMinor(1000000n)).toBe("100.0000");
    expect(fromMinor(0n)).toBe("0.0000");
    expect(fromMinor(1n)).toBe("0.0001");
    expect(fromMinor(-400000n)).toBe("-40.0000");
  });
});

describe("deriveBalance", () => {
  it("sums both sides and reports a zero difference when they agree", () => {
    const balance = deriveBalance([
      line({ debit: "100.0000" }),
      line({ credit: "100.0000" }),
    ]);

    expect(balance.totalDebit).toBe("100.0000");
    expect(balance.totalCredit).toBe("100.0000");
    expect(balance.difference).toBe("0.0000");
    expect(balance.isBalanced).toBe(true);
  });

  it("reports a signed difference when the sides disagree", () => {
    const overDebited = deriveBalance([
      line({ debit: "100.0000" }),
      line({ credit: "90.0000" }),
    ]);
    expect(overDebited.difference).toBe("10.0000");
    expect(overDebited.isBalanced).toBe(false);

    const overCredited = deriveBalance([
      line({ debit: "90.0000" }),
      line({ credit: "100.0000" }),
    ]);
    expect(overCredited.difference).toBe("-10.0000");
    expect(overCredited.isBalanced).toBe(false);
  });

  it("adds fractional amounts exactly, where a float would not", () => {
    // 0.1 + 0.2 !== 0.3 in IEEE 754; in minor units it is exact.
    const balance = deriveBalance([
      line({ debit: "0.1000" }),
      line({ debit: "0.2000" }),
      line({ credit: "0.3000" }),
    ]);

    expect(balance.totalDebit).toBe("0.3000");
    expect(balance.difference).toBe("0.0000");
    expect(balance.isBalanced).toBe(true);
  });

  it("catches a one-fils imbalance rather than rounding it away", () => {
    const balance = deriveBalance([
      line({ debit: "10.0000" }),
      line({ credit: "9.9999" }),
    ]);

    expect(balance.difference).toBe("0.0001");
    expect(balance.isBalanced).toBe(false);
  });

  it("does not call an empty grid balanced, even though zero equals zero", () => {
    expect(deriveBalance([]).isBalanced).toBe(false);
    expect(deriveBalance([line(), line()]).isBalanced).toBe(false);
  });

  it("handles amounts far beyond a safe integer", () => {
    const balance = deriveBalance([
      line({ debit: "9007199254740993.0001" }),
      line({ credit: "9007199254740993.0001" }),
    ]);

    expect(balance.isBalanced).toBe(true);
    expect(balance.difference).toBe("0.0000");
  });
});

describe("isCompleteLine", () => {
  it("accepts a line with an account and exactly one side filled", () => {
    expect(isCompleteLine(line({ debit: "5.0000" }))).toBe(true);
    expect(isCompleteLine(line({ credit: "5.0000" }))).toBe(true);
  });

  it("rejects a line with no account, no amount, or both sides", () => {
    expect(isCompleteLine(line({ accountId: null, debit: "5.0000" }))).toBe(
      false,
    );
    expect(isCompleteLine(line())).toBe(false);
    expect(isCompleteLine(line({ debit: "5.0000", credit: "5.0000" }))).toBe(
      false,
    );
  });
});
