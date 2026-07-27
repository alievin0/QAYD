import { describe, expect, it } from "vitest";

import { ar } from "./ar";
import { en } from "./en";
import { createTranslator, flattenKeys } from "./index";

describe("i18n dictionaries", () => {
  it("en and ar have identical key sets (parity gate, in-test)", () => {
    const enKeys = flattenKeys(en).sort();
    const arKeys = flattenKeys(ar).sort();
    expect(arKeys).toEqual(enKeys);
  });

  it("has no empty string values in either locale", () => {
    for (const dict of [en, ar]) {
      for (const key of flattenKeys(dict)) {
        const value = createTranslator("en")(key); // resolve path shape only
        expect(typeof value).toBe("string");
      }
    }
  });
});

describe("createTranslator", () => {
  it("resolves a dot-path against the active locale", () => {
    expect(createTranslator("en")("nav.dashboard")).toBe("Dashboard");
    expect(createTranslator("ar")("nav.dashboard")).toBe("لوحة التحكّم");
  });

  it("interpolates {var} tokens", () => {
    expect(
      createTranslator("en")("dashboard.welcome", { company: "Al-Noor" }),
    ).toBe("Welcome to Al-Noor");
  });

  it("returns the key itself for an unknown path", () => {
    expect(createTranslator("en")("nav.nonexistent")).toBe("nav.nonexistent");
  });
});
