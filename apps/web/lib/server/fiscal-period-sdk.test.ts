import { describe, expect, it, vi } from "vitest";
import { createClient } from "@qayd/sdk";

/**
 * The SDK's fiscal-period binding (S2-12 prerequisite).
 *
 * A wire test: the endpoint exists and its behaviour is proven by the backend contract suite, so what
 * is worth asserting here is that the client asks for the right thing in the right way — a wrong path
 * or a dropped tenant header would not fail to compile.
 */

interface Capture {
  url: string;
  init: RequestInit;
}

function clientWithCapture(captured: Capture[]) {
  const fetchStub = vi.fn(
    async (url: string | URL | Request, init?: RequestInit) => {
      captured.push({ url: String(url), init: init ?? {} });
      return new Response(
        JSON.stringify({ success: true, data: { fiscal_periods: [] } }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  );

  return createClient({
    baseUrl: "https://api.test/api/v1",
    token: "test-token",
    companyId: "company-uuid",
    fetch: fetchStub as unknown as typeof fetch,
  });
}

function headerOf(init: RequestInit, name: string): string | undefined {
  const headers = init.headers as Record<string, string> | undefined;
  return headers?.[name];
}

describe("fiscalPeriods", () => {
  it("reads the calendar with a plain GET", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).fiscalPeriods();

    expect(captured[0]?.init.method).toBe("GET");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/fiscal-periods",
    );
    // No body: this is a read, and the endpoint has no write counterpart to be confused with.
    expect(captured[0]?.init.body).toBeUndefined();
  });

  it("carries the tenant and bearer headers", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).fiscalPeriods();

    expect(headerOf(captured[0]!.init, "X-Company-Id")).toBe("company-uuid");
    expect(headerOf(captured[0]!.init, "Authorization")).toBe(
      "Bearer test-token",
    );
  });

  it("honours a per-call company override", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).fiscalPeriods("other-company-uuid");

    expect(headerOf(captured[0]!.init, "X-Company-Id")).toBe(
      "other-company-uuid",
    );
  });
});
