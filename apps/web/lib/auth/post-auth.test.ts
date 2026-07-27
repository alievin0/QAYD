import { describe, expect, it } from "vitest";

import { resolvePostAuthDestination } from "./post-auth";

describe("resolvePostAuthDestination", () => {
  it("routes a zero-company user to /onboarding", () => {
    expect(
      resolvePostAuthDestination({
        companies: [],
        company_selection_required: false,
      }),
    ).toBe("/onboarding");
  });

  it("routes a multi-company user needing selection to /select-company", () => {
    expect(
      resolvePostAuthDestination({
        companies: [{}, {}],
        company_selection_required: true,
      }),
    ).toBe("/select-company");
  });

  it("carries a validated next forward to /select-company", () => {
    expect(
      resolvePostAuthDestination(
        { companies: [{}, {}], company_selection_required: true },
        "/accounting/journal-entries/482?tab=lines",
      ),
    ).toBe(
      "/select-company?next=%2Faccounting%2Fjournal-entries%2F482%3Ftab%3Dlines",
    );
  });

  it("drops an unsafe next before routing to /select-company", () => {
    expect(
      resolvePostAuthDestination(
        { companies: [{}, {}], company_selection_required: true },
        "https://evil.example/phish",
      ),
    ).toBe("/select-company");
  });

  it("routes a single-company user to /dashboard", () => {
    expect(
      resolvePostAuthDestination({
        companies: [{}],
        company_selection_required: false,
      }),
    ).toBe("/dashboard");
  });

  it("honors a validated next for a single-company user", () => {
    expect(
      resolvePostAuthDestination(
        { companies: [{}], company_selection_required: false },
        "/reports/trial-balance",
      ),
    ).toBe("/reports/trial-balance");
  });

  it("falls back to /dashboard when next is unsafe", () => {
    expect(
      resolvePostAuthDestination(
        { companies: [{}], company_selection_required: false },
        "//evil.example",
      ),
    ).toBe("/dashboard");
  });
});
