import { describe, expect, it, vi } from "vitest";

// `redirect()` throws internally in Next; mock it so we can assert the target without the throw.
// `vi.hoisted` lets the factory reference the spy despite the mock being hoisted above the imports.
const { redirect } = vi.hoisted(() => ({ redirect: vi.fn() }));
vi.mock("next/navigation", () => ({ redirect }));

import RootPage from "./page";

describe("Root page", () => {
  it("redirects to the default in-app destination", () => {
    RootPage();
    expect(redirect).toHaveBeenCalledWith("/dashboard");
  });
});
