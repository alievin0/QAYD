import { describe, expect, it } from "vitest";

import {
  DEFAULT_POST_AUTH_PATH,
  resolveNext,
  sanitizeNext,
} from "./next-param";

describe("sanitizeNext — open-redirect allow-list", () => {
  it("accepts a same-origin in-app path", () => {
    expect(sanitizeNext("/dashboard")).toBe("/dashboard");
  });

  it("preserves a deep path and its query string", () => {
    expect(sanitizeNext("/accounting/journal-entries/482?tab=lines")).toBe(
      "/accounting/journal-entries/482?tab=lines",
    );
  });

  it("rejects an absolute, non-same-origin URL", () => {
    expect(sanitizeNext("https://evil.example/phish")).toBeNull();
    expect(sanitizeNext("http://localhost.evil.example/x")).toBeNull();
  });

  it("rejects a protocol-relative host", () => {
    expect(sanitizeNext("//evil.example")).toBeNull();
  });

  it("rejects a backslash-smuggled host", () => {
    expect(sanitizeNext("/\\evil.example")).toBeNull();
  });

  it("rejects a path that loops back into the auth flow", () => {
    expect(sanitizeNext("/login")).toBeNull();
    expect(sanitizeNext("/login/whatever")).toBeNull();
    expect(sanitizeNext("/reset-password?token=x")).toBeNull();
  });

  it("rejects the BFF API surface", () => {
    expect(sanitizeNext("/api/auth/me")).toBeNull();
  });

  it("rejects control characters and whitespace (CR/LF smuggling)", () => {
    expect(sanitizeNext("/dashboard\n/login")).toBeNull();
    expect(sanitizeNext("/dash board")).toBeNull();
  });

  it("rejects empty / non-path input", () => {
    expect(sanitizeNext("")).toBeNull();
    expect(sanitizeNext(null)).toBeNull();
    expect(sanitizeNext(undefined)).toBeNull();
    expect(sanitizeNext("dashboard")).toBeNull();
  });
});

describe("resolveNext", () => {
  it("returns the sanitized path when valid", () => {
    expect(resolveNext("/dashboard")).toBe("/dashboard");
  });

  it("falls back to the default destination when invalid", () => {
    expect(resolveNext("https://evil.example")).toBe(DEFAULT_POST_AUTH_PATH);
    expect(resolveNext(null)).toBe(DEFAULT_POST_AUTH_PATH);
  });
});
