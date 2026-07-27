import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// The form reads the App Router + `next` search param; provide controllable stand-ins.
const { push, searchParamsRef } = vi.hoisted(() => ({
  push: vi.fn(),
  searchParamsRef: { current: new URLSearchParams() },
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => searchParamsRef.current,
}));

import { LoginForm } from "./login-form";
import { LocaleProvider } from "../../../lib/i18n/locale-provider";

interface FakeInit {
  status?: number;
  headers?: Record<string, string>;
}

function fakeResponse(body: unknown, init: FakeInit = {}): Response {
  const status = init.status ?? 200;
  return {
    ok: status < 400,
    status,
    headers: new Headers(init.headers ?? {}),
    json: async () => body,
  } as unknown as Response;
}

function renderForm() {
  return render(
    <LocaleProvider initialLocale="en">
      <LoginForm />
    </LocaleProvider>,
  );
}

function submitCredentials() {
  fireEvent.change(screen.getByLabelText(/email/i), {
    target: { value: "user@example.com" },
  });
  fireEvent.change(screen.getByLabelText(/password/i), {
    target: { value: "correct-horse" },
  });
  fireEvent.click(screen.getByRole("button", { name: /sign in/i }));
}

describe("LoginForm — routing by company count", () => {
  beforeEach(() => {
    push.mockReset();
    searchParamsRef.current = new URLSearchParams();
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("routes a zero-company user to /onboarding", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        fakeResponse({
          data: { companies: [], company_selection_required: false },
        }),
      ),
    );
    renderForm();
    submitCredentials();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/onboarding"));
  });

  it("routes a multi-company user needing selection to /select-company", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        fakeResponse({
          data: {
            companies: [{ uuid: "c-1" }, { uuid: "c-2" }],
            company_selection_required: true,
          },
        }),
      ),
    );
    renderForm();
    submitCredentials();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/select-company"));
  });

  it("routes a single-company user to /dashboard", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        fakeResponse({
          data: {
            companies: [{ uuid: "c-1" }],
            company_selection_required: false,
          },
        }),
      ),
    );
    renderForm();
    submitCredentials();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));
  });

  it("honors a validated next deep-link for a single-company user", async () => {
    searchParamsRef.current = new URLSearchParams({ next: "/reports/tb" });
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        fakeResponse({
          data: {
            companies: [{ uuid: "c-1" }],
            company_selection_required: false,
          },
        }),
      ),
    );
    renderForm();
    submitCredentials();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/reports/tb"));
  });

  it("surfaces a 429 lockout with the server Retry-After countdown", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          fakeResponse(
            { code: "ACCOUNT_TEMPORARILY_LOCKED" },
            { status: 429, headers: { "Retry-After": "720" } },
          ),
        ),
    );
    renderForm();
    submitCredentials();

    // The lockout copy interpolates the Retry-After seconds; no redirect fires.
    expect(await screen.findByText(/720/)).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    // Fields disable during the lockout.
    expect(screen.getByLabelText(/email/i)).toBeDisabled();
  });

  it("shows a generic error on invalid credentials without redirecting", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          fakeResponse({ code: "INVALID_CREDENTIALS" }, { status: 401 }),
        ),
    );
    renderForm();
    submitCredentials();

    expect(
      await screen.findByText(/email or password is incorrect/i),
    ).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});
