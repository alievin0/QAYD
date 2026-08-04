import { describe, expect, it, vi } from "vitest";
import { createClient } from "@qayd/sdk";

/**
 * The SDK's trial-balance bindings (S2-12 prerequisite).
 *
 * Three methods and no more: compute, generate, and read a snapshot. Approving a snapshot is a real
 * capability of the API and is deliberately NOT bound here — S2-12 does not approve anything, and a
 * client method that exists is a client method someone will eventually call.
 */

interface Capture {
  url: string;
  init: RequestInit;
}

function clientWithCapture(captured: Capture[]) {
  const fetchStub = vi.fn(
    async (url: string | URL | Request, init?: RequestInit) => {
      captured.push({ url: String(url), init: init ?? {} });
      return new Response(JSON.stringify({ success: true, data: null }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    },
  );

  return createClient({
    baseUrl: "https://api.test/api/v1",
    token: "test-token",
    companyId: "company-uuid",
    fetch: fetchStub as unknown as typeof fetch,
  });
}

describe("trial balance SDK methods", () => {
  it("computes the live trial balance for a period", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).computeTrialBalance(42);

    expect(captured[0]?.init.method).toBe("GET");
    expect(captured[0]?.url).toContain("/accounting/reports/trial-balance");
    expect(captured[0]?.url).toContain("fiscal_period_id=42");
    // A read: no body to be mistaken for a generate.
    expect(captured[0]?.init.body).toBeUndefined();
  });

  it("generates a snapshot by POSTing the period", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).generateTrialBalanceSnapshot({
      fiscal_period_id: 42,
    });

    expect(captured[0]?.init.method).toBe("POST");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/reports/trial-balance",
    );
    expect(JSON.parse(String(captured[0]?.init.body))).toMatchObject({
      fiscal_period_id: 42,
    });
  });

  it("passes the snapshot type through when one is given", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).generateTrialBalanceSnapshot({
      fiscal_period_id: 42,
      type: "unadjusted",
    });

    expect(JSON.parse(String(captured[0]?.init.body))).toMatchObject({
      type: "unadjusted",
    });
  });

  it("reads a stored snapshot by id", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).trialBalanceSnapshot(9);

    expect(captured[0]?.init.method).toBe("GET");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/reports/trial-balance/9",
    );
  });

  it("does not expose an approve binding, which S2-12 has no use for", () => {
    const client = clientWithCapture([]);

    expect(
      (client as unknown as Record<string, unknown>)
        .approveTrialBalanceSnapshot,
    ).toBeUndefined();
  });
});
