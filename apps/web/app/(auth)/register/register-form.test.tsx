import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { RegisterForm } from "./register-form";
import { LocaleProvider } from "../../../lib/i18n/locale-provider";

function fakeResponse(body: unknown, status = 200): Response {
  return {
    ok: status < 400,
    status,
    headers: new Headers(),
    json: async () => body,
  } as unknown as Response;
}

function renderForm() {
  return render(
    <LocaleProvider initialLocale="en">
      <RegisterForm />
    </LocaleProvider>,
  );
}

describe("RegisterForm", () => {
  afterEach(() => vi.restoreAllMocks());

  it("submits and shows the verify-your-email notice on success", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        fakeResponse({ data: { user: { email: "sara@example.com" } } }),
      );
    vi.stubGlobal("fetch", fetchMock);

    renderForm();
    fireEvent.change(screen.getByLabelText(/full name/i), {
      target: { value: "Sara Al-Sabah" },
    });
    fireEvent.change(screen.getByLabelText(/email/i), {
      target: { value: "sara@example.com" },
    });
    fireEvent.change(screen.getByLabelText(/password/i), {
      target: { value: "hunter2hunter2" },
    });
    fireEvent.click(screen.getByRole("button", { name: /create account/i }));

    expect(await screen.findByText(/verify your email/i)).toBeInTheDocument();
    expect(screen.getByText(/sara@example.com/i)).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/auth/register",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("blocks submit and shows an error when the payload is invalid (short password)", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    renderForm();
    fireEvent.change(screen.getByLabelText(/full name/i), {
      target: { value: "Sara" },
    });
    fireEvent.change(screen.getByLabelText(/email/i), {
      target: { value: "sara@example.com" },
    });
    fireEvent.change(screen.getByLabelText(/password/i), {
      target: { value: "short" },
    });
    fireEvent.click(screen.getByRole("button", { name: /create account/i }));

    await waitFor(() =>
      expect(screen.getByRole("alert")).toBeInTheDocument(),
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
