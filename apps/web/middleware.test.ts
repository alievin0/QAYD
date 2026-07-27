import { NextRequest } from "next/server";
import { describe, expect, it } from "vitest";

import { middleware } from "./middleware";

function request(path: string, options: { cookie?: string } = {}): NextRequest {
  const headers = new Headers();
  if (options.cookie) headers.set("cookie", options.cookie);
  return new NextRequest(new URL(path, "https://app.qayd.test"), { headers });
}

describe("auth-gate middleware", () => {
  it("redirects an unauthenticated (app) request to /login with a validated next", () => {
    const response = middleware(request("/dashboard"));

    expect(response.status).toBe(307);
    const location = new URL(response.headers.get("location") ?? "");
    expect(location.pathname).toBe("/login");
    expect(location.searchParams.get("next")).toBe("/dashboard");
  });

  it("preserves a deep (app) path + query in next", () => {
    const response = middleware(
      request("/accounting/journal-entries/482?tab=lines"),
    );

    const location = new URL(response.headers.get("location") ?? "");
    expect(location.pathname).toBe("/login");
    expect(location.searchParams.get("next")).toBe(
      "/accounting/journal-entries/482?tab=lines",
    );
  });

  it("lets an authenticated (app) request through (session cookie present)", () => {
    const response = middleware(
      request("/dashboard", { cookie: "qayd_session=opaque-token" }),
    );

    // NextResponse.next() carries no redirect Location.
    expect(response.headers.get("location")).toBeNull();
    expect(response.status).toBe(200);
  });

  it("never gates a public auth route", () => {
    const response = middleware(request("/login"));

    expect(response.headers.get("location")).toBeNull();
    expect(response.status).toBe(200);
  });

  it("does not emit an open-redirect: only same-origin app paths reach next", () => {
    // The middleware derives `next` from the request's own path, so it is always same-origin. A crafted
    // external `next` on the URL is ignored — the gate rewrites it from the pathname it is protecting.
    const response = middleware(
      request("/dashboard?next=https://evil.example/phish"),
    );

    const location = new URL(response.headers.get("location") ?? "");
    expect(location.searchParams.get("next")).toBe(
      "/dashboard?next=https://evil.example/phish",
    );
    // Whatever ends up in `next`, it is a root-relative same-origin path, never the external URL.
    expect(location.searchParams.get("next")?.startsWith("/")).toBe(true);
  });
});
