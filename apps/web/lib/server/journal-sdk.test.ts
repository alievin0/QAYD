import { describe, expect, it, vi } from "vitest";
import { createClient } from "@qayd/sdk";

/**
 * The SDK's journal-entry methods (S2-11 prerequisite).
 *
 * These assert the WIRE: method, path, body and headers. That matters more than it sounds — the SDK is
 * the only thing between a typed call and an endpoint that moves money, and a wrong verb or a dropped
 * `Idempotency-Key` would not fail to compile. The server's behaviour is proven by the backend contract
 * suite; here `fetch` is stubbed so the test is about what we send.
 *
 * They live in `apps/web` because `@qayd/sdk` has no test runner of its own, and adding one would be
 * tooling this story does not need — the web app already depends on the SDK and already runs vitest.
 */

interface Capture {
  url: string;
  init: RequestInit;
}

function clientWithCapture(captured: Capture[]) {
  const fetchStub = vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
    captured.push({ url: String(url), init: init ?? {} });
    return new Response(JSON.stringify({ success: true, data: null }), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    });
  });

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

describe("journal entry SDK methods", () => {
  it("lists entries with query parameters", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).listJournalEntries({
      page: 2,
      per_page: 10,
      status: "draft",
    });

    expect(captured[0]?.init.method).toBe("GET");
    expect(captured[0]?.url).toContain("/accounting/journal-entries");
    expect(captured[0]?.url).toContain("page=2");
    expect(captured[0]?.url).toContain("per_page=10");
    expect(captured[0]?.url).toContain("status=draft");
  });

  it("reads one entry by id", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).journalEntry(42);

    expect(captured[0]?.init.method).toBe("GET");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/42",
    );
  });

  it("creates a draft with money as strings, never numbers", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).createJournalEntry({
      journal_date: "2026-02-10",
      entry_type: "manual",
      currency_code: "KWD",
      lines: [
        { account_id: 1, debit: "100.0000", credit: "0" },
        { account_id: 2, debit: "0", credit: "100.0000" },
      ],
    });

    expect(captured[0]?.init.method).toBe("POST");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries",
    );

    const sent = JSON.parse(String(captured[0]?.init.body)) as {
      lines: { debit: unknown }[];
    };
    // A float here would silently lose precision the ledger cannot recover.
    expect(typeof sent.lines[0]?.debit).toBe("string");
    expect(sent.lines[0]?.debit).toBe("100.0000");
  });

  it("sends the version on update, so the server can refuse a stale edit", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).updateJournalEntry(7, {
      version: 3,
      journal_date: "2026-02-10",
      entry_type: "manual",
      currency_code: "KWD",
      lines: [{ account_id: 1, debit: "5.0000", credit: "0" }],
    });

    expect(captured[0]?.init.method).toBe("PATCH");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/7",
    );
    expect(JSON.parse(String(captured[0]?.init.body))).toMatchObject({
      version: 3,
    });
  });

  it("submits with the version", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).submitJournalEntry(7, { version: 1 });

    expect(captured[0]?.init.method).toBe("POST");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/7/submit",
    );
  });

  it("sends the Idempotency-Key header when posting", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).postJournalEntry(7, "retry-key-1");

    expect(captured[0]?.init.method).toBe("POST");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/7/post",
    );
    expect(headerOf(captured[0]!.init, "Idempotency-Key")).toBe("retry-key-1");
  });

  it("omits the Idempotency-Key header when none is given, keeping it opt-in", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).postJournalEntry(7);

    expect(headerOf(captured[0]!.init, "Idempotency-Key")).toBeUndefined();
    // The rest of the client contract is untouched by the per-call header.
    expect(headerOf(captured[0]!.init, "X-Company-Id")).toBe("company-uuid");
    expect(headerOf(captured[0]!.init, "Authorization")).toBe(
      "Bearer test-token",
    );
  });

  it("sends the reason when reversing", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).reverseJournalEntry(7, {
      reason: "Duplicate capture",
    });

    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/7/reverse",
    );
    expect(JSON.parse(String(captured[0]?.init.body))).toMatchObject({
      reason: "Duplicate capture",
    });
  });

  it("voids by id with no body of its own", async () => {
    const captured: Capture[] = [];
    await clientWithCapture(captured).voidJournalEntry(7);

    expect(captured[0]?.init.method).toBe("POST");
    expect(captured[0]?.url).toBe(
      "https://api.test/api/v1/accounting/journal-entries/7/void",
    );
  });
});
